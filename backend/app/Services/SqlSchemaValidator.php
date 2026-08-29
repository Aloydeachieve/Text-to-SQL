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
     * @return array{valid: bool, reason: ?string}
     */
    public function validate(string $sql): array
    {
        $normalized = trim($sql);
        if (empty($normalized)) {
            return ['valid' => false, 'reason' => 'SQL query is empty.'];
        }

        $schema = $this->schemaService->getTablesAndColumns();

        // 1. Extract referenced tables from FROM and JOIN clauses
        preg_match_all('/\b(?:from|join)\s+[`"]?([a-zA-Z0-9_]+)[`"]?/i', $normalized, $tableMatches);
        $referencedTables = array_unique(array_map('strtolower', $tableMatches[1] ?? []));

        if (empty($referencedTables)) {
            // Some select queries might not have a table (e.g. SELECT 1), we allow them but log a warning.
            return ['valid' => true, 'reason' => null];
        }

        // Verify all referenced tables exist
        foreach ($referencedTables as $table) {
            if (!array_key_exists($table, $schema)) {
                return [
                    'valid' => false,
                    'reason' => "Unknown table referenced: '{$table}'"
                ];
            }
        }

        // 2. Build ignored identifiers list (SQL keywords, functions, tables, and table aliases)
        $sqlKeywordsAndFunctions = [
            'select', 'from', 'where', 'join', 'on', 'group', 'by', 'order', 'limit', 'as', 
            'and', 'or', 'in', 'between', 'like', 'is', 'null', 'not', 'exists', 'having', 
            'left', 'right', 'inner', 'outer', 'cross', 'union', 'all', 'desc', 'asc',
            'sum', 'count', 'avg', 'min', 'max', 'date_format', 'concat', 'coalesce', 
            'ifnull', 'nullif', 'year', 'month', 'day', 'now', 'curdate', 'datediff', 'round', 
            'floor', 'ceil', 'abs', 'with', 'over', 'partition', 'rank', 'dense_rank', 'row_number'
        ];

        $ignored = array_merge($sqlKeywordsAndFunctions, $referencedTables);

        // Extract table aliases (e.g. FROM customers c or JOIN orders AS o)
        preg_match_all('/\b(?:from|join)\s+[`"]?([a-zA-Z0-9_]+)[`"]?(?:\s+(?:as\s+)?([a-zA-Z0-9_]+))?/i', $normalized, $aliasMatches, PREG_SET_ORDER);
        foreach ($aliasMatches as $match) {
            if (isset($match[2]) && !empty($match[2])) {
                $alias = strtolower($match[2]);
                // If it's not a keyword, add it to ignored
                if (!in_array($alias, $ignored)) {
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
        $sqlWithoutStrings = preg_replace('/([\'"])(.*?)\1/', '', $normalized);
        preg_match_all('/\b[a-zA-Z_][a-zA-Z0-9_]*\b/', $sqlWithoutStrings, $wordMatches);
        $words = array_unique(array_map('strtolower', $wordMatches[0] ?? []));

        // Gather all valid columns across all referenced tables
        $validColumns = [];
        foreach ($referencedTables as $table) {
            if (isset($schema[$table])) {
                $validColumns = array_merge($validColumns, $schema[$table]);
            }
        }
        $validColumns = array_unique($validColumns);

        // Check if any word is an unknown column reference
        foreach ($words as $word) {
            // Skip keywords, functions, tables, and aliases
            if (in_array($word, $ignored)) {
                continue;
            }

            // If it's not a valid column in any of the referenced tables, reject it
            if (!in_array($word, $validColumns)) {
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
