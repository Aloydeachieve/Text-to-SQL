<?php

namespace App\Services;

class SchemaRelevanceService
{
    /**
     * Common business terminology mapping to tables, columns, and query concepts.
     *
     * @var array<string, array{tables: list<string>, columns: list<string>, concepts: list<string>}>
     */
    protected array $businessTerms = [
        'sales' => [
            'tables' => ['orders', 'order_items', 'sales', 'transactions', 'invoices', 'payments', 'order_lines'],
            'columns' => ['total_amount', 'total_price', 'amount', 'price', 'total', 'subtotal', 'revenue', 'quantity'],
            'concepts' => ['revenue', 'volume', 'turnover'],
        ],
        'revenue' => [
            'tables' => ['orders', 'order_items', 'sales', 'transactions', 'invoices', 'payments'],
            'columns' => ['total_amount', 'total_price', 'amount', 'price', 'total', 'subtotal', 'revenue'],
            'concepts' => ['income', 'money', 'earnings'],
        ],
        'earnings' => [
            'tables' => ['orders', 'order_items', 'sales', 'transactions'],
            'columns' => ['total_amount', 'total_price', 'amount', 'revenue'],
            'concepts' => ['revenue'],
        ],
        'spending' => [
            'tables' => ['orders', 'order_items', 'customers', 'transactions'],
            'columns' => ['total_amount', 'total_price', 'amount'],
            'concepts' => ['spend', 'expenditure'],
        ],
        'spend' => [
            'tables' => ['orders', 'order_items', 'customers'],
            'columns' => ['total_amount', 'total_price', 'amount'],
            'concepts' => ['spending'],
        ],
        'customer' => [
            'tables' => ['customers', 'clients', 'accounts', 'users', 'members', 'buyers'],
            'columns' => ['customer_id', 'client_id', 'user_id', 'name', 'email', 'phone', 'city'],
            'concepts' => ['people', 'clients'],
        ],
        'customers' => [
            'tables' => ['customers', 'clients', 'accounts', 'users', 'members', 'buyers'],
            'columns' => ['customer_id', 'client_id', 'user_id', 'name', 'email', 'phone', 'city'],
            'concepts' => ['people', 'clients'],
        ],
        'clients' => [
            'tables' => ['clients', 'customers', 'accounts', 'users'],
            'columns' => ['client_id', 'customer_id', 'name', 'email'],
            'concepts' => ['customers'],
        ],
        'buyers' => [
            'tables' => ['customers', 'users', 'buyers', 'orders'],
            'columns' => ['customer_id', 'name', 'email'],
            'concepts' => ['customers'],
        ],
        'product' => [
            'tables' => ['products', 'items', 'inventory', 'catalog', 'merchandise'],
            'columns' => ['product_id', 'item_id', 'name', 'price', 'stock', 'category'],
            'concepts' => ['items', 'inventory'],
        ],
        'products' => [
            'tables' => ['products', 'items', 'inventory', 'catalog', 'merchandise'],
            'columns' => ['product_id', 'item_id', 'name', 'price', 'stock', 'category'],
            'concepts' => ['items', 'inventory'],
        ],
        'items' => [
            'tables' => ['items', 'products', 'order_items', 'inventory'],
            'columns' => ['item_id', 'product_id', 'name', 'price', 'quantity'],
            'concepts' => ['products'],
        ],
        'inventory' => [
            'tables' => ['inventory', 'products', 'items', 'stock'],
            'columns' => ['stock', 'quantity', 'product_id', 'price'],
            'concepts' => ['stock', 'products'],
        ],
        'order' => [
            'tables' => ['orders', 'order_items', 'purchases', 'transactions', 'checkouts'],
            'columns' => ['order_id', 'customer_id', 'order_date', 'total_amount', 'status'],
            'concepts' => ['purchases'],
        ],
        'orders' => [
            'tables' => ['orders', 'order_items', 'purchases', 'transactions', 'checkouts'],
            'columns' => ['order_id', 'customer_id', 'order_date', 'total_amount', 'status'],
            'concepts' => ['purchases'],
        ],
        'purchases' => [
            'tables' => ['orders', 'order_items', 'purchases', 'transactions'],
            'columns' => ['order_id', 'total_amount', 'order_date'],
            'concepts' => ['orders'],
        ],
        'bought' => [
            'tables' => ['customers', 'orders', 'order_items', 'products'],
            'columns' => ['customer_id', 'product_id', 'order_id', 'quantity'],
            'concepts' => ['purchases', 'orders'],
        ],
        'placed' => [
            'tables' => ['orders', 'customers'],
            'columns' => ['order_id', 'customer_id', 'order_date'],
            'concepts' => ['orders'],
        ],
        'top-selling' => [
            'tables' => ['products', 'order_items', 'orders'],
            'columns' => ['quantity', 'total_price', 'product_id', 'name'],
            'concepts' => ['volume', 'revenue'],
        ],
        'best-selling' => [
            'tables' => ['products', 'order_items', 'orders'],
            'columns' => ['quantity', 'total_price', 'product_id', 'name'],
            'concepts' => ['volume', 'revenue'],
        ],
        'popular' => [
            'tables' => ['products', 'order_items'],
            'columns' => ['quantity', 'product_id', 'name'],
            'concepts' => ['volume'],
        ],
        'month' => [
            'tables' => ['orders', 'transactions'],
            'columns' => ['order_date', 'created_at', 'date', 'timestamp'],
            'concepts' => ['date', 'time'],
        ],
        'monthly' => [
            'tables' => ['orders', 'transactions'],
            'columns' => ['order_date', 'created_at', 'date', 'timestamp'],
            'concepts' => ['date', 'time'],
        ],
        'year' => [
            'tables' => ['orders', 'transactions'],
            'columns' => ['order_date', 'created_at', 'date', 'timestamp'],
            'concepts' => ['date', 'time'],
        ],
        'average' => [
            'tables' => ['orders', 'order_items'],
            'columns' => ['total_amount', 'unit_price', 'price'],
            'concepts' => ['avg'],
        ],
    ];

    /**
     * Select relevant schema elements for a question.
     *
     * @param string $question
     * @param array{
     *     tables: list<array{name: string, columns: list<array{name: string, type: string, primary?: bool, foreign?: bool, nullable?: bool, referenced_table?: ?string, referenced_column?: ?string}>}>,
     *     relationships?: list<array{from: string, to: string, from_table?: string, from_column?: string, to_table?: string, to_column?: string, label?: string}>
     * } $normalizedSchema
     * @return array{
     *     schema: array,
     *     is_subset: bool,
     *     matched_tables: list<string>,
     *     bridge_tables: list<string>,
     *     all_table_names: list<string>
     * }
     */
    public function selectRelevantSchema(string $question, array $normalizedSchema): array
    {
        $allTables = $normalizedSchema['tables'] ?? [];
        $relationships = $normalizedSchema['relationships'] ?? [];
        $allTableNames = array_map(fn($t) => $t['name'], $allTables);

        if (empty($allTables)) {
            return [
                'schema' => $normalizedSchema,
                'is_subset' => false,
                'matched_tables' => [],
                'bridge_tables' => [],
                'all_table_names' => [],
            ];
        }

        // If relevance filtering is disabled via config, return full schema (subject to size protection)
        if (!config('schema.enable_relevance_filter', true)) {
            return [
                'schema' => $this->applyLargeSchemaProtection($normalizedSchema, $question),
                'is_subset' => false,
                'matched_tables' => $allTableNames,
                'bridge_tables' => [],
                'all_table_names' => $allTableNames,
            ];
        }

        // 1. Tokenize question and extract candidate keywords
        $tokens = $this->tokenizeQuestion($question);

        // 2. Score tables based on name match, column match, and business term mapping
        $tableScores = [];
        $matchedDirectly = [];

        foreach ($allTables as $table) {
            $tableName = strtolower($table['name']);
            $score = 0;

            // Direct table name matching
            foreach ($tokens as $token) {
                if ($token === $tableName) {
                    $score += 20;
                } elseif (str_contains($tableName, $token) || str_contains($token, $tableName)) {
                    $score += 10;
                } elseif ($this->singularize($token) === $this->singularize($tableName)) {
                    $score += 15;
                }
            }

            // Column name matching
            $matchedCols = 0;
            foreach ($table['columns'] ?? [] as $col) {
                $colName = strtolower($col['name']);
                // Don't score foreign key columns ending with _id as direct matches (they are relational links)
                if (str_ends_with($colName, '_id')) {
                    continue;
                }
                foreach ($tokens as $token) {
                    if ($token === $colName) {
                        $score += 6;
                        $matchedCols++;
                    } elseif (str_contains($colName, $token) && strlen($token) >= 3) {
                        $score += 3;
                        $matchedCols++;
                    }
                }
            }

            // Business terms matching
            foreach ($tokens as $token) {
                if (isset($this->businessTerms[$token])) {
                    $termInfo = $this->businessTerms[$token];
                    // Check if table is in business term tables (exact or singular form match)
                    foreach ($termInfo['tables'] as $bTable) {
                        if ($tableName === $bTable || $this->singularize($tableName) === $this->singularize($bTable)) {
                            $score += 12;
                        }
                    }
                    // Check if columns match business term columns (ignore foreign keys)
                    foreach ($table['columns'] ?? [] as $col) {
                        $colName = strtolower($col['name']);
                        if (str_ends_with($colName, '_id')) {
                            continue;
                        }
                        if (in_array($colName, $termInfo['columns'], true)) {
                            $score += 4;
                        }
                    }
                }
            }

            if ($score > 0) {
                $tableScores[$tableName] = $score;
                if ($score >= 8) {
                    $matchedDirectly[] = $tableName;
                }
            }
        }

        // 3. Fallback check: If no tables matched with reasonable confidence, fall back to full schema
        if (empty($matchedDirectly)) {
            // Check if top scored table exists
            arsort($tableScores);
            if (!empty($tableScores) && reset($tableScores) >= 5) {
                $matchedDirectly[] = array_key_first($tableScores);
            } else {
                // Fallback to full schema (subject to large schema protection)
                return [
                    'schema' => $this->applyLargeSchemaProtection($normalizedSchema, $question),
                    'is_subset' => false,
                    'matched_tables' => $allTableNames,
                    'bridge_tables' => [],
                    'all_table_names' => $allTableNames,
                ];
            }
        }

        $matchedDirectly = array_values(array_unique($matchedDirectly));

        // 4. In-Memory Graph Traversal: Find bridge/junction tables connecting matched tables
        $adjacency = $this->buildGraph($relationships);
        $bridgeTables = [];

        if (count($matchedDirectly) >= 2) {
            // Find shortest paths connecting all pairs of directly matched tables
            for ($i = 0; $i < count($matchedDirectly); $i++) {
                for ($j = $i + 1; $j < count($matchedDirectly); $j++) {
                    $path = $this->findShortestPath($matchedDirectly[$i], $matchedDirectly[$j], $adjacency);
                    if (!empty($path)) {
                        foreach ($path as $node) {
                            if (!in_array($node, $matchedDirectly, true) && !in_array($node, $bridgeTables, true)) {
                                $bridgeTables[] = $node;
                            }
                        }
                    }
                }
            }
        } elseif (count($matchedDirectly) === 1) {
            // If only 1 table matched, add directly connected 1-hop foreign key neighbors
            $singleTable = $matchedDirectly[0];
            if (isset($adjacency[$singleTable])) {
                foreach ($adjacency[$singleTable] as $neighbor) {
                    if (!in_array($neighbor, $matchedDirectly, true)) {
                        $bridgeTables[] = $neighbor;
                    }
                }
            }
        }

        // Combine selected tables
        $selectedTableNames = array_values(array_unique(array_merge($matchedDirectly, $bridgeTables)));

        // 5. If all tables were selected or matched, return full schema
        if (count($selectedTableNames) >= count($allTables)) {
            return [
                'schema' => $this->applyLargeSchemaProtection($normalizedSchema, $question),
                'is_subset' => false,
                'matched_tables' => $matchedDirectly,
                'bridge_tables' => $bridgeTables,
                'all_table_names' => $allTableNames,
            ];
        }

        // 6. Build the relevant schema subset
        $tableLookup = [];
        foreach ($allTables as $tbl) {
            $tableLookup[strtolower($tbl['name'])] = $tbl;
        }

        $relevantTables = [];
        foreach ($selectedTableNames as $name) {
            if (isset($tableLookup[$name])) {
                $relevantTables[] = $tableLookup[$name];
            }
        }

        // Filter relationships to only those connecting the selected tables
        $relevantRelationships = [];
        foreach ($relationships as $rel) {
            $fromTbl = $this->extractTableName($rel['from'] ?? ($rel['from_table'] ?? ''));
            $toTbl = $this->extractTableName($rel['to'] ?? ($rel['to_table'] ?? ''));

            if (in_array($fromTbl, $selectedTableNames, true) && in_array($toTbl, $selectedTableNames, true)) {
                $relevantRelationships[] = $rel;
            }
        }

        $filteredSchema = [
            'tables' => $relevantTables,
            'relationships' => $relevantRelationships,
        ];

        // Apply large schema protection limits on columns / tables
        $protectedSchema = $this->applyLargeSchemaProtection($filteredSchema, $question);

        return [
            'schema' => $protectedSchema,
            'is_subset' => true,
            'matched_tables' => $matchedDirectly,
            'bridge_tables' => $bridgeTables,
            'all_table_names' => $allTableNames,
        ];
    }

    /**
     * Apply large schema protection: limits on tables in context, columns per table, and context size.
     */
    public function applyLargeSchemaProtection(array $schema, string $question): array
    {
        $maxTables = (int) config('schema.max_tables_in_context', 15);
        $maxCols = (int) config('schema.max_columns_per_table', 30);

        $tables = $schema['tables'] ?? [];
        $relationships = $schema['relationships'] ?? [];

        // 1. Enforce table count limit if exceeded
        if (count($tables) > $maxTables) {
            $tables = array_slice($tables, 0, $maxTables);
            $retainedNames = array_map(fn($t) => strtolower($t['name']), $tables);

            // Filter relationships to retained tables
            $relationships = array_values(array_filter($relationships, function ($rel) use ($retainedNames) {
                $from = $this->extractTableName($rel['from'] ?? '');
                $to = $this->extractTableName($rel['to'] ?? '');
                return in_array($from, $retainedNames, true) && in_array($to, $retainedNames, true);
            }));
        }

        // 2. Enforce columns per table limit
        $tokens = $this->tokenizeQuestion($question);
        $protectedTables = [];

        foreach ($tables as $table) {
            $cols = $table['columns'] ?? [];
            if (count($cols) <= $maxCols) {
                $protectedTables[] = $table;
                continue;
            }

            // Prioritize: Primary Keys, Foreign Keys, Question-Matched Columns, Timestamps/Dates
            $pks = [];
            $fks = [];
            $matched = [];
            $dates = [];
            $others = [];

            foreach ($cols as $col) {
                $colName = strtolower($col['name']);
                if (!empty($col['primary'])) {
                    $pks[] = $col;
                } elseif (!empty($col['foreign'])) {
                    $fks[] = $col;
                } elseif (in_array($colName, $tokens, true)) {
                    $matched[] = $col;
                } elseif (str_contains($colName, 'date') || str_contains($colName, 'time') || str_contains($colName, 'at')) {
                    $dates[] = $col;
                } else {
                    $others[] = $col;
                }
            }

            $selectedCols = array_merge($pks, $fks, $matched, $dates);
            $remainingSlots = $maxCols - count($selectedCols);
            if ($remainingSlots > 0 && !empty($others)) {
                $selectedCols = array_merge($selectedCols, array_slice($others, 0, $remainingSlots));
            }

            $table['columns'] = $selectedCols;
            $protectedTables[] = $table;
        }

        return [
            'tables' => $protectedTables,
            'relationships' => $relationships,
        ];
    }

    /**
     * Build textual prompt context for the AI from the relevant schema.
     */
    public function formatPromptContext(array $relevantSchema, string $driver = 'mysql'): string
    {
        $lines = [];
        $dialect = in_array(strtolower($driver), ['pgsql', 'postgres', 'postgresql']) ? 'PostgreSQL' : 'MySQL';

        $lines[] = "Database Dialect: {$dialect}";
        $lines[] = "Available Tables and Columns:";

        foreach ($relevantSchema['tables'] ?? [] as $table) {
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

        if (!empty($relevantSchema['relationships'])) {
            $lines[] = "\nForeign Key Relationships:";
            foreach ($relevantSchema['relationships'] as $rel) {
                $from = $rel['from'] ?? "{$rel['from_table']}.{$rel['from_column']}";
                $to = $rel['to'] ?? "{$rel['to_table']}.{$rel['to_column']}";
                $lines[] = "- {$from} references {$to}";
            }
        }

        $result = implode("\n", $lines);

        // Cap context bytes if configured
        $maxBytes = (int) config('schema.max_context_bytes', 12000);
        if (strlen($result) > $maxBytes) {
            $result = substr($result, 0, $maxBytes) . "\n... [Context capped for size safety]";
        }

        return $result;
    }

    /**
     * Tokenize question into normalized words.
     *
     * @param string $question
     * @return list<string>
     */
    protected function tokenizeQuestion(string $question): array
    {
        $clean = preg_replace('/[^a-zA-Z0-9_\-\s]/', ' ', strtolower($question)) ?? strtolower($question);
        $rawTokens = preg_split('/\s+/', trim($clean));
        $tokens = [];

        $stopWords = ['a', 'an', 'the', 'in', 'on', 'at', 'for', 'to', 'of', 'and', 'or', 'is', 'are', 'was', 'were', 'our', 'what', 'which', 'who', 'how', 'many', 'much', 'with', 'by', 'from', 'this', 'that', 'do', 'we', 'have', 'get', 'show', 'find', 'me', 'list'];

        foreach ($rawTokens as $t) {
            $t = trim($t, " -_");
            if (strlen($t) >= 2 && !in_array($t, $stopWords, true)) {
                $tokens[] = $t;
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Build an undirected adjacency graph from relationships.
     *
     * @param array $relationships
     * @return array<string, list<string>>
     */
    protected function buildGraph(array $relationships): array
    {
        $adj = [];
        foreach ($relationships as $rel) {
            $fromTbl = $this->extractTableName($rel['from'] ?? ($rel['from_table'] ?? ''));
            $toTbl = $this->extractTableName($rel['to'] ?? ($rel['to_table'] ?? ''));

            if (!empty($fromTbl) && !empty($toTbl) && $fromTbl !== $toTbl) {
                $adj[$fromTbl][] = $toTbl;
                $adj[$toTbl][] = $fromTbl;
            }
        }

        foreach ($adj as $node => $neighbors) {
            $adj[$node] = array_values(array_unique($neighbors));
        }

        return $adj;
    }

    /**
     * Breadth-First Search to find the shortest connecting path between two tables.
     *
     * @param string $start
     * @param string $target
     * @param array<string, list<string>> $adj
     * @return list<string>
     */
    protected function findShortestPath(string $start, string $target, array $adj): array
    {
        if ($start === $target) {
            return [$start];
        }

        $queue = [[$start]];
        $visited = [$start => true];

        while (!empty($queue)) {
            $path = array_shift($queue);
            $node = end($path);

            if ($node === $target) {
                return $path;
            }

            foreach ($adj[$node] ?? [] as $neighbor) {
                if (!isset($visited[$neighbor])) {
                    $visited[$neighbor] = true;
                    $newPath = $path;
                    $newPath[] = $neighbor;
                    $queue[] = $newPath;
                }
            }
        }

        return [];
    }

    /**
     * Extract table name from a dotted expression like 'orders.customer_id'.
     */
    protected function extractTableName(string $ref): string
    {
        $parts = explode('.', strtolower(trim($ref)));
        return $parts[0] ?? '';
    }

    /**
     * Crude English singularization for plural words.
     */
    protected function singularize(string $word): string
    {
        $w = strtolower($word);
        if (str_ends_with($w, 'ies')) {
            return substr($w, 0, -3) . 'y';
        }
        if (str_ends_with($w, 'ses') || str_ends_with($w, 'xes')) {
            return substr($w, 0, -2);
        }
        if (str_ends_with($w, 's') && !str_ends_with($w, 'ss')) {
            return substr($w, 0, -1);
        }
        return $w;
    }
}
