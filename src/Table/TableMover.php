<?php

declare(strict_types=1);

namespace PhpMyAdmin\Table;

use PhpMyAdmin\ConfigStorage\Relation;
use PhpMyAdmin\DatabaseInterface;
use PhpMyAdmin\Dbal\ConnectionType;
use PhpMyAdmin\Message;
use PhpMyAdmin\Plugins;
use PhpMyAdmin\Plugins\Export\ExportSql;
use PhpMyAdmin\SqlParser\Components\Expression;
use PhpMyAdmin\SqlParser\Components\OptionsArray;
use PhpMyAdmin\SqlParser\Context;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statements\AlterStatement;
use PhpMyAdmin\SqlParser\Statements\CreateStatement;
use PhpMyAdmin\SqlParser\Statements\DropStatement;
use PhpMyAdmin\Util;

use function __;
use function htmlspecialchars;
use function implode;
use function in_array;
use function sprintf;

class TableMover
{
    /**
     * Copies or renames table
     *
     * @param string $sourceDb    source database
     * @param string $sourceTable source table
     * @param string $targetDb    target database
     * @param string $targetTable target table
     * @param bool   $move        whether to move
     */
    public static function moveCopy(
        string $sourceDb,
        string $sourceTable,
        string $targetDb,
        string $targetTable,
        MoveScope $what,
        bool $move,
        MoveMode $mode,
        bool $addDropIfExists,
    ): bool {
        $GLOBALS['errorUrl'] ??= null;
        $dbi = DatabaseInterface::getInstance();
        $relation = new Relation($dbi);

        // Try moving the tables directly, using native `RENAME` statement.
        if ($move && $what === MoveScope::Data) {
            $tbl = new Table($sourceTable, $sourceDb, $dbi);
            if ($tbl->rename($targetTable, $targetDb)) {
                $GLOBALS['message'] = $tbl->getLastMessage();

                return true;
            }
        }

        $missingDatabaseMessage = self::checkWhetherDatabasesExist($dbi, $sourceDb, $targetDb);
        if ($missingDatabaseMessage !== null) {
            $GLOBALS['message'] = $missingDatabaseMessage;

            return false;
        }

        // Setting required export settings.
        $GLOBALS['asfile'] = 1;

        // Selecting the database could avoid some problems with replicated
        // databases, when moving table from replicated one to not replicated one.
        $dbi->selectDb($targetDb);

        /**
         * The full name of source table, quoted.
         */
        $source = Util::backquote($sourceDb) . '.' . Util::backquote($sourceTable);
        /**
         * The full name of target table, quoted.
         */
        $target = Util::backquote($targetDb) . '.' . Util::backquote($targetTable);

        // No table is created when this is a data-only operation.
        if ($what !== MoveScope::DataOnly) {
            /**
             * Instance used for exporting the current structure of the table.
             *
             * @var ExportSql $exportSqlPlugin
             */
            $exportSqlPlugin = Plugins::getPlugin('export', 'sql', [
                'export_type' => 'table',
                'single_table' => false,
            ]);
            // It is better that all identifiers are quoted
            $exportSqlPlugin->useSqlBackquotes(true);

            $noConstraintsComments = true;
            $GLOBALS['sql_constraints_query'] = '';
            // set the value of global sql_auto_increment variable
            if (isset($_POST['sql_auto_increment'])) {
                $GLOBALS['sql_auto_increment'] = $_POST['sql_auto_increment'];
            }

            $isView = (new Table($sourceTable, $sourceDb, $dbi))->isView();
            /**
             * The old structure of the table.
             */
            $sqlStructure = $exportSqlPlugin->getTableDef($sourceDb, $sourceTable, false, false, $isView);

            unset($noConstraintsComments);

            // -----------------------------------------------------------------
            // Phase 0: Preparing structures used.

            /**
             * The destination where the table is moved or copied to.
             */
            $destination = new Expression($targetDb, $targetTable, '');

            // Find server's SQL mode so the builder can generate correct
            // queries.
            // One of the options that alters the behaviour is `ANSI_QUOTES`.
            Context::setMode((string) $dbi->fetchValue('SELECT @@sql_mode'));

            // -----------------------------------------------------------------
            // Phase 1: Dropping existent element of the same name (if exists
            // and required).

            if ($addDropIfExists) {
                /**
                 * Drop statement used for building the query.
                 */
                $statement = new DropStatement();

                $tbl = new Table($targetTable, $targetDb, $dbi);

                $statement->options = new OptionsArray(
                    [$tbl->isView() ? 'VIEW' : 'TABLE', 'IF EXISTS'],
                );

                $statement->fields = [$destination];

                // Building the query.
                $dropQuery = $statement->build() . ';';

                // Executing it.
                $dbi->query($dropQuery);
                $GLOBALS['sql_query'] .= "\n" . $dropQuery;

                // If an existing table gets deleted, maintain any entries for
                // the PMA_* tables.
                $maintainRelations = true;
            }

            // -----------------------------------------------------------------
            // Phase 2: Generating the new query of this structure.

            /**
             * The parser responsible for parsing the old queries.
             */
            $parser = new Parser($sqlStructure);

            if (! empty($parser->statements[0])) {

                /**
                 * The CREATE statement of this structure.
                 *
                 * @var CreateStatement $statement
                 */
                $statement = $parser->statements[0];

                // Changing the destination.
                $statement->name = $destination;

                // Building back the query.
                $sqlStructure = $statement->build() . ';';

                // This is to avoid some issues when renaming databases with views
                // See: https://github.com/phpmyadmin/phpmyadmin/issues/16422
                if ($move) {
                    $dbi->selectDb($targetDb);
                }

                // Executing it
                $dbi->query($sqlStructure);
                $GLOBALS['sql_query'] .= "\n" . $sqlStructure;
            }

            // -----------------------------------------------------------------
            // Phase 3: Adding constraints.
            // All constraint names are removed because they must be unique.

            if ($move && ! empty($GLOBALS['sql_constraints_query'])) {
                $GLOBALS['sql_constraints_query'] = self::getConstraintsSqlWithoutNames(
                    $GLOBALS['sql_constraints_query'],
                    $destination,
                );

                $GLOBALS['sql_query'] .= "\n" . $GLOBALS['sql_constraints_query'];

                // We can only execute it if both tables have been created.
                // When performing the whole database move,
                // the constraints can only be created after all tables have been created.
                if ($mode === MoveMode::SingleTable) {
                    $dbi->query($GLOBALS['sql_constraints_query']);
                    unset($GLOBALS['sql_constraints_query']);
                }
            }

            // -----------------------------------------------------------------
            // Phase 4: Adding indexes.
            // View phase 3.

            if (! empty($GLOBALS['sql_indexes'])) {
                $parser = new Parser($GLOBALS['sql_indexes']);

                $GLOBALS['sql_indexes'] = '';
                /**
                 * The ALTER statement that generates the indexes.
                 *
                 * @var AlterStatement $statement
                 */
                foreach ($parser->statements as $statement) {
                    // Changing the altered table to the destination.
                    $statement->table = $destination;

                    // Removing the name of the constraints.
                    foreach ($statement->altered as $altered) {
                        // All constraint names are removed because they must be unique.
                        if (! $altered->options->has('CONSTRAINT')) {
                            continue;
                        }

                        $altered->field = null;
                    }

                    // Building back the query.
                    $sqlIndex = $statement->build() . ';';

                    // Executing it.
                    $dbi->query($sqlIndex);

                    $GLOBALS['sql_indexes'] .= $sqlIndex;
                }

                $GLOBALS['sql_query'] .= "\n" . $GLOBALS['sql_indexes'];
                unset($GLOBALS['sql_indexes']);
            }

            // -----------------------------------------------------------------
            // Phase 5: Adding AUTO_INCREMENT.

            if (! empty($GLOBALS['sql_auto_increments'])) {
                $parser = new Parser($GLOBALS['sql_auto_increments']);

                /**
                 * The ALTER statement that alters the AUTO_INCREMENT value.
                 */
                $statement = $parser->statements[0];
                if ($statement instanceof AlterStatement) {
                    // Changing the altered table to the destination.
                    $statement->table = $destination;

                    // Building back the query.
                    $GLOBALS['sql_auto_increments'] = $statement->build() . ';';

                    // Executing it.
                    $dbi->query($GLOBALS['sql_auto_increments']);
                    $GLOBALS['sql_query'] .= "\n" . $GLOBALS['sql_auto_increments'];
                }

                unset($GLOBALS['sql_auto_increments']);
            }
        } else {
            $GLOBALS['sql_query'] = '';
        }

        $table = new Table($targetTable, $targetDb, $dbi);
        // Copy the data unless this is a VIEW
        if (($what === MoveScope::Data || $what === MoveScope::DataOnly) && ! $table->isView()) {
            $sqlSetMode = "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'";
            $dbi->query($sqlSetMode);
            $GLOBALS['sql_query'] .= "\n\n" . $sqlSetMode . ';';

            $oldTable = new Table($sourceTable, $sourceDb, $dbi);
            $nonGeneratedCols = $oldTable->getNonGeneratedColumns();
            if ($nonGeneratedCols !== []) {
                $sqlInsertData = 'INSERT INTO ' . $target . '('
                    . implode(', ', $nonGeneratedCols)
                    . ') SELECT ' . implode(', ', $nonGeneratedCols)
                    . ' FROM ' . $source;

                $dbi->query($sqlInsertData);
                $GLOBALS['sql_query'] .= "\n\n" . $sqlInsertData . ';';
            }
        }

        $relationParameters = $relation->getRelationParameters();

        // Drops old table if the user has requested to move it
        if ($move) {
            // This could avoid some problems with replicated databases, when
            // moving table from replicated one to not replicated one
            $dbi->selectDb($sourceDb);

            $sourceTableObj = new Table($sourceTable, $sourceDb, $dbi);
            $sqlDropQuery = $sourceTableObj->isView() ? 'DROP VIEW' : 'DROP TABLE';

            $sqlDropQuery .= ' ' . $source;
            $dbi->query($sqlDropQuery);

            // Rename table in configuration storage
            $relation->renameTable($sourceDb, $targetDb, $sourceTable, $targetTable);

            $GLOBALS['sql_query'] .= "\n\n" . $sqlDropQuery . ';';

            return true;
        }

        // we are copying
        // Create new entries as duplicates from old PMA DBs
        if ($what === MoveScope::DataOnly || isset($maintainRelations)) {
            return true;
        }

        if ($relationParameters->columnCommentsFeature !== null) {
            // Get all comments and MIME-Types for current table
            $commentsCopyRs = $dbi->queryAsControlUser(
                'SELECT column_name, comment'
                . ($relationParameters->browserTransformationFeature !== null
                ? ', mimetype, transformation, transformation_options'
                : '')
                . ' FROM '
                . Util::backquote($relationParameters->columnCommentsFeature->database)
                . '.'
                . Util::backquote($relationParameters->columnCommentsFeature->columnInfo)
                . ' WHERE '
                . ' db_name = ' . $dbi->quoteString($sourceDb, ConnectionType::ControlUser)
                . ' AND '
                . ' table_name = ' . $dbi->quoteString($sourceTable, ConnectionType::ControlUser),
            );

            // Write every comment as new copied entry. [MIME]
            foreach ($commentsCopyRs as $commentsCopyRow) {
                $newCommentQuery = 'REPLACE INTO '
                    . Util::backquote($relationParameters->columnCommentsFeature->database)
                    . '.' . Util::backquote($relationParameters->columnCommentsFeature->columnInfo)
                    . ' (db_name, table_name, column_name, comment'
                    . ($relationParameters->browserTransformationFeature !== null
                        ? ', mimetype, transformation, transformation_options'
                        : '')
                    . ') VALUES(' . $dbi->quoteString($targetDb, ConnectionType::ControlUser)
                    . ',' . $dbi->quoteString($targetTable, ConnectionType::ControlUser) . ','
                    . $dbi->quoteString($commentsCopyRow['column_name'], ConnectionType::ControlUser)
                    . ','
                    . $dbi->quoteString($commentsCopyRow['comment'], ConnectionType::ControlUser)
                    . ($relationParameters->browserTransformationFeature !== null
                        ? ',' . $dbi->quoteString($commentsCopyRow['mimetype'], ConnectionType::ControlUser)
                        . ',' . $dbi->quoteString($commentsCopyRow['transformation'], ConnectionType::ControlUser)
                        . ','
                        . $dbi->quoteString($commentsCopyRow['transformation_options'], ConnectionType::ControlUser)
                        : '')
                    . ')';
                $dbi->queryAsControlUser($newCommentQuery);
            }

            unset($commentsCopyRs);
        }

        // duplicating the bookmarks must not be done here, but
        // just once per db

        $getFields = ['display_field'];
        $whereFields = ['db_name' => $sourceDb, 'table_name' => $sourceTable];
        $newFields = ['db_name' => $targetDb, 'table_name' => $targetTable];
        self::duplicateInfo('displaywork', 'table_info', $getFields, $whereFields, $newFields);

        /** @todo revise this code when we support cross-db relations */
        $getFields = ['master_field', 'foreign_table', 'foreign_field'];
        $whereFields = ['master_db' => $sourceDb, 'master_table' => $sourceTable];
        $newFields = ['master_db' => $targetDb, 'foreign_db' => $targetDb, 'master_table' => $targetTable];
        self::duplicateInfo('relwork', 'relation', $getFields, $whereFields, $newFields);

        $getFields = ['foreign_field', 'master_table', 'master_field'];
        $whereFields = ['foreign_db' => $sourceDb, 'foreign_table' => $sourceTable];
        $newFields = ['master_db' => $targetDb, 'foreign_db' => $targetDb, 'foreign_table' => $targetTable];
        self::duplicateInfo('relwork', 'relation', $getFields, $whereFields, $newFields);

        return true;
    }

    /**
     * Inserts existing entries in a PMA_* table by reading a value from an old
     * entry
     *
     * @param string   $work        The array index, which Relation feature to check ('relwork', 'commwork', ...)
     * @param string   $table       The array index, which PMA-table to update ('bookmark', 'relation', ...)
     * @param string[] $getFields   Which fields will be SELECT'ed from the old entry
     * @param mixed[]  $whereFields Which fields will be used for the WHERE query (array('FIELDNAME' => 'FIELDVALUE'))
     * @param mixed[]  $newFields   Which fields will be used as new VALUES. These are the important keys which differ
     *                            from the old entry (array('FIELDNAME' => 'NEW FIELDVALUE'))
     */
    public static function duplicateInfo(
        string $work,
        string $table,
        array $getFields,
        array $whereFields,
        array $newFields,
    ): int|bool {
        $dbi = DatabaseInterface::getInstance();
        $relation = new Relation($dbi);
        $relationParameters = $relation->getRelationParameters();
        $relationParams = $relationParameters->toArray();
        $lastId = -1;

        if (! isset($relationParams[$work], $relationParams[$table]) || ! $relationParams[$work]) {
            return true;
        }

        $selectParts = [];
        $rowFields = [];
        foreach ($getFields as $getField) {
            $selectParts[] = Util::backquote($getField);
            $rowFields[] = $getField;
        }

        $whereParts = [];
        foreach ($whereFields as $where => $value) {
            $whereParts[] = Util::backquote((string) $where) . ' = '
                . $dbi->quoteString((string) $value, ConnectionType::ControlUser);
        }

        $newParts = [];
        $newValueParts = [];
        foreach ($newFields as $where => $value) {
            $newParts[] = Util::backquote((string) $where);
            $newValueParts[] = $dbi->quoteString((string) $value, ConnectionType::ControlUser);
        }

        $tableCopyQuery = '
            SELECT ' . implode(', ', $selectParts) . '
              FROM ' . Util::backquote($relationParameters->db) . '.'
            . Util::backquote((string) $relationParams[$table]) . '
             WHERE ' . implode(' AND ', $whereParts);

        // must use DatabaseInterface::QUERY_BUFFERED here, since we execute
        // another query inside the loop
        $tableCopyRs = $dbi->queryAsControlUser($tableCopyQuery);

        foreach ($tableCopyRs as $tableCopyRow) {
            $valueParts = [];
            foreach ($tableCopyRow as $key => $val) {
                if (! in_array($key, $rowFields)) {
                    continue;
                }

                $valueParts[] = $dbi->quoteString($val, ConnectionType::ControlUser);
            }

            $newTableQuery = 'INSERT IGNORE INTO '
                . Util::backquote($relationParameters->db)
                . '.' . Util::backquote((string) $relationParams[$table])
                . ' (' . implode(', ', $selectParts) . ', '
                . implode(', ', $newParts) . ') VALUES ('
                . implode(', ', $valueParts) . ', '
                . implode(', ', $newValueParts) . ')';

            $dbi->queryAsControlUser($newTableQuery);
            $lastId = $dbi->insertId();
        }

        return $lastId;
    }

    private static function getConstraintsSqlWithoutNames(string $constraintsSql, Expression $destination): string
    {
        $parser = new Parser($constraintsSql);

        /**
         * The ALTER statement that generates the constraints.
         *
         * @var AlterStatement $statement
         */
        $statement = $parser->statements[0];

        // Changing the altered table to the destination.
        $statement->table = $destination;

        // Removing the name of the constraints.
        foreach ($statement->altered as $altered) {
            // All constraint names are removed because they must be unique.
            if (! $altered->options->has('CONSTRAINT')) {
                continue;
            }

            $altered->field = null;
        }

        // Building back the query.
        return $statement->build() . ';';
    }

    private static function checkWhetherDatabasesExist(
        DatabaseInterface $dbi,
        string $sourceDb,
        string $targetDb,
    ): Message|null {
        $databaseList = $dbi->getDatabaseList();

        if (! $databaseList->exists($sourceDb)) {
            return Message::rawError(
                sprintf(
                    __('Source database `%s` was not found!'),
                    htmlspecialchars($sourceDb),
                ),
            );
        }

        if (! $databaseList->exists($targetDb)) {
            return Message::rawError(
                sprintf(
                    __('Target database `%s` was not found!'),
                    htmlspecialchars($targetDb),
                ),
            );
        }

        return null;
    }
}
