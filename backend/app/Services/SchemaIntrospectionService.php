<?php

namespace App\Services;

use App\Models\DatabaseConnection;
use Illuminate\Database\ConnectionInterface;
use Throwable;

class SchemaIntrospectionService
{
    protected DatabaseConnectionManager $connectionManager;

    public function __construct(DatabaseConnectionManager $connectionManager)
    {
        $this->connectionManager = $connectionManager;
    }

    /**
     * Determine if a table name is an internal/system table that should be excluded.
     */
    public function isExcludedTable(string $tableName, string $driver = 'mysql'): bool
    {
        $name = strtolower(trim($tableName));
        if (empty($name)) {
            return true;
        }

        $excludedCommon = config('schema.excluded_tables.common', [
            'migrations',
            'personal_access_tokens',
            'failed_jobs',
            'job_batches',
            'jobs',
            'cache',
            'cache_locks',
            'sessions',
            'sqlite_sequence',
            'flyway_schema_history',
            'alembic_version',
            'schema_migrations',
        ]);

        $driverKey = strtolower($driver) === 'pgsql' ? 'pgsql' : 'mysql';
        $excludedDriver = config("schema.excluded_tables.{$driverKey}", []);
        $allExcluded = array_map('strtolower', array_merge($excludedCommon, $excludedDriver));

        if (in_array($name, $allExcluded, true)) {
            return true;
        }

        $prefixes = config('schema.excluded_prefixes', ['telescope_', 'pulse_', 'pg_', 'raster_']);
        foreach ($prefixes as $prefix) {
            if (str_starts_with($name, strtolower($prefix))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Introspect an authorized customer database connection and return a normalized schema representation.
     *
     * @param DatabaseConnection $databaseConnection
     * @return array{
     *     tables: list<array{
     *         name: string,
     *         columns: list<array{
     *             name: string,
     *             type: string,
     *             nullable: bool,
     *             primary: bool,
     *             foreign: bool,
     *             referenced_table: ?string,
     *             referenced_column: ?string
     *         }>
     *     }>,
     *     relationships: list<array{
     *         from: string,
     *         to: string,
     *         from_table: string,
     *         from_column: string,
     *         to_table: string,
     *         to_column: string,
     *         label: string
     *     }>
     * }
     */
    public function introspect(DatabaseConnection $databaseConnection): array
    {
        $connection = $this->connectionManager->getConnection($databaseConnection);
        $driver = strtolower($databaseConnection->driver);

        if ($driver === 'mysql') {
            return $this->introspectMySql($connection, $databaseConnection->database);
        }

        if ($driver === 'pgsql') {
            return $this->introspectPostgreSql($connection);
        }

        throw new \InvalidArgumentException("Unsupported driver '{$driver}' for schema introspection.");
    }

    /**
     * Introspect a MySQL database via information_schema.
     */
    public function introspectMySql(ConnectionInterface $connection, string $databaseName): array
    {
        // 1. Fetch tables excluding system/internal metadata
        $tablesRaw = $connection->select(
            "SELECT table_name 
             FROM information_schema.tables 
             WHERE table_schema = ? AND table_type = 'BASE TABLE'
             ORDER BY table_name ASC",
            [$databaseName]
        );

        $tableNames = array_values(array_filter(array_map(function ($row) {
            $arr = array_change_key_case((array) $row, CASE_LOWER);
            return (string) ($arr['table_name'] ?? '');
        }, $tablesRaw), function ($tbl) {
            return !empty($tbl) && !$this->isExcludedTable($tbl, 'mysql');
        }));

        // 2. Fetch foreign key relationships first to enrich column metadata
        $relationshipsRaw = $connection->select(
            "SELECT table_name, column_name, referenced_table_name, referenced_column_name 
             FROM information_schema.key_column_usage 
             WHERE table_schema = ? 
               AND referenced_table_name IS NOT NULL",
            [$databaseName]
        );

        $relationships = [];
        $foreignKeys = [];
        foreach ($relationshipsRaw as $rel) {
            $arr = array_change_key_case((array) $rel, CASE_LOWER);
            $fromTable = (string) ($arr['table_name'] ?? '');
            $fromCol = (string) ($arr['column_name'] ?? '');
            $toTable = (string) ($arr['referenced_table_name'] ?? '');
            $toCol = (string) ($arr['referenced_column_name'] ?? '');

            // Exclude foreign keys connected to internal/system tables
            if ($this->isExcludedTable($fromTable, 'mysql') || $this->isExcludedTable($toTable, 'mysql')) {
                continue;
            }

            $foreignKeys["{$fromTable}.{$fromCol}"] = [
                'table' => $toTable,
                'column' => $toCol,
            ];

            $relationships[] = [
                'from' => "{$fromTable}.{$fromCol}",
                'to' => "{$toTable}.{$toCol}",
                'from_table' => $fromTable,
                'from_column' => $fromCol,
                'to_table' => $toTable,
                'to_column' => $toCol,
                'label' => "references {$toTable}.{$toCol}",
            ];
        }

        // 3. Fetch columns
        $columnsRaw = $connection->select(
            "SELECT table_name, column_name, data_type, is_nullable, column_key
             FROM information_schema.columns 
             WHERE table_schema = ?
             ORDER BY table_name ASC, ordinal_position ASC",
            [$databaseName]
        );

        $columnsByTable = [];
        foreach ($columnsRaw as $col) {
            $arr = array_change_key_case((array) $col, CASE_LOWER);
            $tbl = (string) ($arr['table_name'] ?? '');
            if ($this->isExcludedTable($tbl, 'mysql')) {
                continue;
            }

            $colName = (string) ($arr['column_name'] ?? '');
            $isPk = strtoupper($arr['column_key'] ?? '') === 'PRI';
            $isFk = isset($foreignKeys["{$tbl}.{$colName}"]);
            $refTable = $isFk ? $foreignKeys["{$tbl}.{$colName}"]['table'] : null;
            $refCol = $isFk ? $foreignKeys["{$tbl}.{$colName}"]['column'] : null;

            $typeStr = strtolower((string) ($arr['data_type'] ?? 'text'));
            if ($isPk) {
                $typeStr .= ' (PK)';
            } elseif ($isFk) {
                $typeStr .= ' (FK)';
            }

            $columnsByTable[$tbl][] = [
                'name' => $colName,
                'type' => $typeStr,
                'nullable' => strtoupper((string) ($arr['is_nullable'] ?? '')) === 'YES',
                'primary' => $isPk,
                'foreign' => $isFk,
                'referenced_table' => $refTable,
                'referenced_column' => $refCol,
            ];
        }

        $tables = [];
        foreach ($tableNames as $tbl) {
            $tables[] = [
                'name' => $tbl,
                'columns' => $columnsByTable[$tbl] ?? [],
            ];
        }

        return [
            'tables' => $tables,
            'relationships' => $relationships,
        ];
    }

    /**
     * Introspect a PostgreSQL database via information_schema.
     */
    public function introspectPostgreSql(ConnectionInterface $connection): array
    {
        // 1. Fetch tables from public schema excluding system/internal metadata
        $tablesRaw = $connection->select(
            "SELECT table_name 
             FROM information_schema.tables 
             WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
             ORDER BY table_name ASC"
        );

        $tableNames = array_values(array_filter(array_map(function ($row) {
            $arr = array_change_key_case((array) $row, CASE_LOWER);
            return (string) ($arr['table_name'] ?? '');
        }, $tablesRaw), function ($tbl) {
            return !empty($tbl) && !$this->isExcludedTable($tbl, 'pgsql');
        }));

        // 2. Fetch primary key column names
        $pkRows = $connection->select(
            "SELECT tc.table_name, kcu.column_name
             FROM information_schema.table_constraints tc
             JOIN information_schema.key_column_usage kcu
               ON tc.constraint_name = kcu.constraint_name
              AND tc.table_schema = kcu.table_schema
             WHERE tc.constraint_type = 'PRIMARY KEY'
               AND tc.table_schema = 'public'"
        );
        $primaryKeys = [];
        foreach ($pkRows as $pk) {
            $arr = array_change_key_case((array) $pk, CASE_LOWER);
            $primaryKeys["{$arr['table_name']}.{$arr['column_name']}"] = true;
        }

        // 3. Fetch foreign key relationships
        $relRows = $connection->select(
            "SELECT
                tc.table_name, 
                kcu.column_name, 
                ccu.table_name AS foreign_table_name,
                ccu.column_name AS foreign_column_name 
             FROM information_schema.table_constraints AS tc 
             JOIN information_schema.key_column_usage AS kcu
               ON tc.constraint_name = kcu.constraint_name
              AND tc.table_schema = kcu.table_schema
             JOIN information_schema.constraint_column_usage AS ccu
               ON ccu.constraint_name = tc.constraint_name
              AND ccu.table_schema = tc.table_schema
             WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = 'public'"
        );

        $relationships = [];
        $foreignKeys = [];
        foreach ($relRows as $rel) {
            $arr = array_change_key_case((array) $rel, CASE_LOWER);
            $fromTable = (string) ($arr['table_name'] ?? '');
            $fromCol = (string) ($arr['column_name'] ?? '');
            $toTable = (string) ($arr['foreign_table_name'] ?? '');
            $toCol = (string) ($arr['foreign_column_name'] ?? '');

            if ($this->isExcludedTable($fromTable, 'pgsql') || $this->isExcludedTable($toTable, 'pgsql')) {
                continue;
            }

            $foreignKeys["{$fromTable}.{$fromCol}"] = [
                'table' => $toTable,
                'column' => $toCol,
            ];

            $relationships[] = [
                'from' => "{$fromTable}.{$fromCol}",
                'to' => "{$toTable}.{$toCol}",
                'from_table' => $fromTable,
                'from_column' => $fromCol,
                'to_table' => $toTable,
                'to_column' => $toCol,
                'label' => "references {$toTable}.{$toCol}",
            ];
        }

        // 4. Fetch columns
        $columnsRaw = $connection->select(
            "SELECT table_name, column_name, data_type, is_nullable
             FROM information_schema.columns 
             WHERE table_schema = 'public'
             ORDER BY table_name ASC, ordinal_position ASC"
        );

        $columnsByTable = [];
        foreach ($columnsRaw as $col) {
            $arr = array_change_key_case((array) $col, CASE_LOWER);
            $tbl = (string) ($arr['table_name'] ?? '');
            if ($this->isExcludedTable($tbl, 'pgsql')) {
                continue;
            }

            $colName = (string) ($arr['column_name'] ?? '');
            $isPk = isset($primaryKeys["{$tbl}.{$colName}"]);
            $isFk = isset($foreignKeys["{$tbl}.{$colName}"]);
            $refTable = $isFk ? $foreignKeys["{$tbl}.{$colName}"]['table'] : null;
            $refCol = $isFk ? $foreignKeys["{$tbl}.{$colName}"]['column'] : null;

            $typeStr = strtolower((string) ($arr['data_type'] ?? 'text'));
            if ($isPk) {
                $typeStr .= ' (PK)';
            } elseif ($isFk) {
                $typeStr .= ' (FK)';
            }

            $columnsByTable[$tbl][] = [
                'name' => $colName,
                'type' => $typeStr,
                'nullable' => strtoupper((string) ($arr['is_nullable'] ?? '')) === 'YES',
                'primary' => $isPk,
                'foreign' => $isFk,
                'referenced_table' => $refTable,
                'referenced_column' => $refCol,
            ];
        }

        $tables = [];
        foreach ($tableNames as $tbl) {
            $tables[] = [
                'name' => $tbl,
                'columns' => $columnsByTable[$tbl] ?? [],
            ];
        }

        return [
            'tables' => $tables,
            'relationships' => $relationships,
        ];
    }

    /**
     * Convert normalized schema into a table-to-columns mapping.
     *
     * @param array $normalizedSchema
     * @return array<string, list<string>>
     */
    public function getTablesAndColumns(array $normalizedSchema): array
    {
        $map = [];
        foreach ($normalizedSchema['tables'] ?? [] as $table) {
            $tableName = $table['name'];
            $columns = [];
            foreach ($table['columns'] ?? [] as $column) {
                $columns[] = $column['name'];
            }
            $map[$tableName] = $columns;
        }

        return $map;
    }

    /**
     * Format normalized schema into textual context for AI prompts.
     *
     * @param array $normalizedSchema
     * @return string
     */
    public function getPromptContext(array $normalizedSchema): string
    {
        $lines = [];
        foreach ($normalizedSchema['tables'] ?? [] as $table) {
            $cols = [];
            foreach ($table['columns'] ?? [] as $col) {
                $desc = "{$col['name']} ({$col['type']}";
                if (!empty($col['foreign']) && !empty($col['referenced_table']) && !empty($col['referenced_column'])) {
                    $desc .= ", FK to {$col['referenced_table']}.{$col['referenced_column']}";
                }
                if (!empty($col['nullable'])) {
                    $desc .= ', nullable';
                }
                $desc .= ')';
                $cols[] = $desc;
            }
            $colsStr = implode(', ', $cols);
            $lines[] = "- Table: {$table['name']}\n  Columns: {$colsStr}";
        }

        if (!empty($normalizedSchema['relationships'])) {
            $lines[] = "\nRelationships:";
            foreach ($normalizedSchema['relationships'] as $rel) {
                $from = $rel['from'] ?? "{$rel['from_table']}.{$rel['from_column']}";
                $to = $rel['to'] ?? "{$rel['to_table']}.{$rel['to_column']}";
                $lines[] = "- {$from} references {$to}";
            }
        }

        return implode("\n", $lines);
    }
}
