<?php

namespace App\Services;

class SqlSchemaValidator
{
    protected DatabaseSchemaService $schemaService;

    public function __construct(DatabaseSchemaService $schemaService)
    {
        $this->schemaService = $schemaService;
    }

    /**
     * Validate the SQL query against the known database schema.
     *
     * @param string $sql
     * @param array<string, list<string>>|null $schema
     * @param string $driver
     * @return array{valid: bool, reason: ?string}
     */
    public function validate(string $sql, ?array $schema = null, string $driver = 'mysql'): array
    {
        $normalized = trim($sql);
        if (empty($normalized)) {
            return ['valid' => false, 'reason' => 'SQL query is empty.'];
        }

        $schema = $schema ?? $this->schemaService->getTablesAndColumns();

        // 1. Extract referenced tables from FROM and JOIN clauses
        // Mask EXTRACT(... FROM ...) expressions so the internal FROM keyword is not mistaken for a table clause
        $sqlForTables = preg_replace('/\bextract\s*\([^)]+\)/i', '', $normalized) ?? $normalized;
        preg_match_all('/\b(?:from|join)\s+[`"]?([a-zA-Z0-9_]+)[`"]?/i', $sqlForTables, $tableMatches);
        $referencedTables = array_unique(array_map('strtolower', $tableMatches[1] ?? []));

        if (empty($referencedTables)) {
            // Some select queries might not have a table (e.g. SELECT 1), we allow them
            return ['valid' => true, 'reason' => null];
        }

        // Verify all referenced tables exist in schema
        foreach ($referencedTables as $table) {
            if (!array_key_exists($table, $schema)) {
                return [
                    'valid' => false,
                    'reason' => "Unknown table referenced: '{$table}'"
                ];
            }
        }

        // 2. Build comprehensive SQL keywords and dialect-aware scalar functions
        $commonKeywordsAndFunctions = [
            'select', 'from', 'where', 'join', 'on', 'group', 'by', 'order', 'limit', 'as', 
            'and', 'or', 'in', 'between', 'like', 'ilike', 'is', 'null', 'not', 'exists', 'having', 
            'left', 'right', 'inner', 'outer', 'cross', 'union', 'all', 'desc', 'asc',
            'sum', 'count', 'avg', 'min', 'max', 'date_format', 'to_char', 'concat', 'coalesce', 
            'ifnull', 'nullif', 'year', 'month', 'day', 'hour', 'minute', 'second',
            'days', 'months', 'years', 'weeks', 'week', 'quarter', 'hours', 'minutes', 'seconds',
            'now', 'curdate', 'current_date', 'current_time', 'current_timestamp', 'localtime', 'localtimestamp',
            'date', 'time', 'timestamp', 'interval', 'datediff', 'date_sub', 'date_add', 'extract',
            'round', 'floor', 'ceil', 'ceiling', 'abs', 'mod', 'power', 'sqrt',
            'cast', 'convert', 'trim', 'ltrim', 'rtrim', 'lower', 'upper', 'substring', 'substr', 'length', 'replace',
            'case', 'when', 'then', 'else', 'end', 'true', 'false', 'boolean',
            'with', 'over', 'partition', 'rank', 'dense_rank', 'row_number', 'distinct',
            'offset', 'using', 'greatest', 'least',
        ];

        // MySQL-specific functions & keywords
        $mysqlKeywords = [
            'str_to_date', 'curtime', 'unix_timestamp', 'from_unixtime', 'group_concat', 'isnull',
        ];

        // PostgreSQL-specific functions & keywords
        $pgsqlKeywords = [
            'date_trunc', 'to_date', 'to_timestamp', 'date_part', 'age', 'make_date', 'make_interval',
            'generate_series', 'bool_or', 'bool_and', 'string_agg', 'array_agg', 'filter',
        ];

        $isPgsql = in_array(strtolower($driver), ['pgsql', 'postgres', 'postgresql'], true);
        $dialectKeywords = $isPgsql ? $pgsqlKeywords : $mysqlKeywords;

        $sqlKeywordsAndFunctions = array_merge($commonKeywordsAndFunctions, $dialectKeywords);
        $ignored = array_merge($sqlKeywordsAndFunctions, $referencedTables);

        // Extract table aliases (e.g. FROM customers c or JOIN orders AS o)
        preg_match_all('/\b(?:from|join)\s+[`"]?([a-zA-Z0-9_]+)[`"]?(?:\s+(?:as\s+)?([a-zA-Z0-9_]+))?/i', $normalized, $aliasMatches, PREG_SET_ORDER);
        foreach ($aliasMatches as $match) {
            if (isset($match[2]) && !empty($match[2])) {
                $alias = strtolower($match[2]);
                if (!in_array($alias, $ignored, true)) {
                    $ignored[] = $alias;
                }
            }
        }

        // Extract custom SELECT aliases (e.g. SELECT COUNT(*) AS total_customers)
        preg_match_all('/\bas\s+[`"]?([a-zA-Z0-9_]+)[`"]?/i', $normalized, $asMatches);
        if (isset($asMatches[1])) {
            foreach ($asMatches[1] as $alias) {
                $ignored[] = strtolower($alias);
            }
        }

        // 3. Extract all identifiers (words starting with letter/underscore)
        // Strip string literals to avoid matching words inside strings as column names
        $sqlWithoutStrings = preg_replace('/([\'"])(.*?)\1/', '', $normalized) ?? $normalized;
        preg_match_all('/\b[a-zA-Z_][a-zA-Z0-9_]*\b/', $sqlWithoutStrings, $wordMatches);
        $words = array_unique(array_map('strtolower', $wordMatches[0] ?? []));

        // Gather all valid columns across all referenced tables
        $validColumns = [];
        foreach ($referencedTables as $table) {
            if (isset($schema[$table])) {
                $validColumns = array_merge($validColumns, $schema[$table]);
            }
        }
        $validColumns = array_unique(array_map('strtolower', $validColumns));

        // Check if any word is an unknown column reference
        foreach ($words as $word) {
            // Skip keywords, functions, tables, and aliases
            if (in_array($word, $ignored, true)) {
                continue;
            }

            // If it's not a valid column in any of the referenced tables, reject it
            if (!in_array($word, $validColumns, true)) {
                return [
                    'valid' => false,
                    'reason' => "Unknown column reference: '{$word}' in query context."
                ];
            }
        }

        return [
            'valid' => true,
            'reason' => null
        ];
    }
}
