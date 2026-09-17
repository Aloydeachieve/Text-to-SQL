<?php

namespace App\Services;

use Illuminate\Support\Str;

class QueryInterpretationService
{
    /**
     * Complete interpretation of a SQL query including tables, joins, grain, filters,
     * aggregations, and multiplication / fan-out risk detection.
     *
     * @param string $sql
     * @param array|null $schemaDetails Full schema metadata with 'tables' and 'relationships'
     * @return array{
     *     tables: list<string>,
     *     joins: list<string>,
     *     grain: string,
     *     filters: list<string>,
     *     aggregations: list<string>,
     *     multiplication_risk: ?array{
     *         detected: bool,
     *         warning: string,
     *         details: string,
     *         recommendation: string
     *     }
     * }
     */
    public function interpret(string $sql, ?array $schemaDetails = null): array
    {
        $normalizedSql = trim($sql);
        $sqlWithoutStrings = preg_replace('/([\'"])(.*?)\1/', '', $normalizedSql) ?? $normalizedSql;

        $aliases = $this->extractTableAliases($sqlWithoutStrings);
        $tables = $this->extractTables($sqlWithoutStrings, $aliases);
        $joins = $this->extractJoins($sqlWithoutStrings, $aliases);
        $filters = $this->extractFilters($normalizedSql, $aliases);
        $grouping = $this->extractGrouping($sqlWithoutStrings, $aliases);
        $aggregations = $this->extractAggregations($sqlWithoutStrings, $aliases);

        $relationships = $schemaDetails['relationships'] ?? $this->getDefaultRelationships();

        $grain = $this->determineGrain($tables, $joins, $grouping, $aggregations, $relationships);
        $multiplicationRisk = $this->detectMultiplicationRisk($tables, $joins, $aggregations, $grouping, $relationships);
        $complexity = $this->detectComplexityRisk($sqlWithoutStrings, $tables, $joins, $filters);

        return [
            'tables' => $tables,
            'joins' => $joins,
            'grain' => $grain,
            'filters' => $filters,
            'aggregations' => $aggregations,
            'multiplication_risk' => $multiplicationRisk,
            'risk_level' => $complexity['risk_level'],
            'risk_reasons' => $complexity['risk_reasons'],
        ];
    }

    /**
     * Extract table aliases from FROM and JOIN clauses.
     * e.g. "FROM orders o JOIN order_items oi" -> ['o' => 'orders', 'oi' => 'order_items']
     *
     * @param string $sql
     * @return array<string, string>
     */
    public function extractTableAliases(string $sql): array
    {
        $aliases = [];

        // Match "FROM table [AS] alias" or "JOIN table [AS] alias"
        $pattern = '/\b(?:from|join)\s+[`"]?([a-zA-Z0-9_]+)[`"]?(?:\s+(?:as\s+)?([a-zA-Z0-9_]+))?/i';
        if (preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $table = strtolower($match[1]);
                // Ensure table is an alias identifier itself
                $aliases[$table] = $table;

                if (!empty($match[2])) {
                    $alias = strtolower($match[2]);
                    // Ignore SQL keywords that might be parsed if there is no alias (e.g. "JOIN table ON")
                    $reserved = ['on', 'where', 'join', 'left', 'right', 'inner', 'outer', 'cross', 'group', 'order', 'limit', 'using', 'as', 'natural'];
                    if (!in_array($alias, $reserved, true)) {
                        $aliases[$alias] = $table;
                    }
                }
            }
        }

        return $aliases;
    }

    /**
     * Extract list of distinct tables referenced in FROM and JOIN clauses.
     *
     * @param string $sql
     * @param array<string, string> $aliases
     * @return list<string>
     */
    public function extractTables(string $sql, array $aliases): array
    {
        preg_match_all('/\b(?:from|join)\s+[`"]?([a-zA-Z0-9_]+)[`"]?/i', $sql, $matches);
        $rawTables = $matches[1] ?? [];
        $tables = [];

        foreach ($rawTables as $t) {
            $name = strtolower($t);
            $tables[] = $aliases[$name] ?? $name;
        }

        return array_values(array_unique($tables));
    }

    /**
     * Extract join conditions and format as "table1.col → table2.col".
     *
     * @param string $sql
     * @param array<string, string> $aliases
     * @return list<string>
     */
    public function extractJoins(string $sql, array $aliases): array
    {
        $joins = [];

        // Match JOIN ... ON col1 = col2
        $onPattern = '/\bon\s+[`"]?([a-zA-Z0-9_]+)[`"]?\.([a-zA-Z0-9_]+)\s*=\s*[`"]?([a-zA-Z0-9_]+)[`"]?\.([a-zA-Z0-9_]+)/i';
        if (preg_match_all($onPattern, $sql, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $t1Alias = strtolower($match[1]);
                $c1 = strtolower($match[2]);
                $t2Alias = strtolower($match[3]);
                $c2 = strtolower($match[4]);

                $t1 = $aliases[$t1Alias] ?? $t1Alias;
                $t2 = $aliases[$t2Alias] ?? $t2Alias;

                // Format parent.id → child.parent_id if recognizable
                if ($c1 === 'id' || str_ends_with($c2, '_id')) {
                    $joins[] = "{$t1}.{$c1} → {$t2}.{$c2}";
                } elseif ($c2 === 'id' || str_ends_with($c1, '_id')) {
                    $joins[] = "{$t2}.{$c2} → {$t1}.{$c1}";
                } else {
                    $joins[] = "{$t1}.{$c1} → {$t2}.{$c2}";
                }
            }
        }

        // Also check for joins specified in WHERE clause: WHERE a.id = b.a_id
        if (empty($joins) && preg_match('/\bwhere\s+(.*?)(?:\bgroup\s+by\b|\border\s+by\b|\blimit\b|$)/is', $sql, $whereMatch)) {
            $whereClause = $whereMatch[1];
            if (preg_match_all('/[`"]?([a-zA-Z0-9_]+)[`"]?\.([a-zA-Z0-9_]+)\s*=\s*[`"]?([a-zA-Z0-9_]+)[`"]?\.([a-zA-Z0-9_]+)/i', $whereClause, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $t1Alias = strtolower($match[1]);
                    $c1 = strtolower($match[2]);
                    $t2Alias = strtolower($match[3]);
                    $c2 = strtolower($match[4]);

                    $t1 = $aliases[$t1Alias] ?? $t1Alias;
                    $t2 = $aliases[$t2Alias] ?? $t2Alias;

                    if ($t1 !== $t2) {
                        if ($c1 === 'id' || str_ends_with($c2, '_id')) {
                            $joins[] = "{$t1}.{$c1} → {$t2}.{$c2}";
                        } else {
                            $joins[] = "{$t2}.{$c2} → {$t1}.{$c1}";
                        }
                    }
                }
            }
        }

        return array_values(array_unique($joins));
    }

    /**
     * Extract WHERE filters.
     *
     * @param string $sql
     * @param array<string, string> $aliases
     * @return list<string>
     */
    public function extractFilters(string $sql, array $aliases): array
    {
        $filters = [];

        if (preg_match('/\bwhere\s+(.*?)(?:\bgroup\s+by\b|\border\s+by\b|\blimit\b|$)/is', $sql, $whereMatch)) {
            $rawParts = preg_split('/\s+(?:and|or)\s+/i', trim($whereMatch[1]));
            if ($rawParts) {
                foreach ($rawParts as $part) {
                    $trimmed = trim($part, " ()\r\n\t");
                    if (empty($trimmed)) {
                        continue;
                    }

                    // Skip pure join conditions (e.g. o.id = oi.order_id)
                    if (preg_match('/^[a-zA-Z0-9_]+\.[a-zA-Z0-9_]+\s*=\s*[a-zA-Z0-9_]+\.[a-zA-Z0-9_]+$/', $trimmed, $m)) {
                        continue;
                    }

                    // Replace aliases in filter: "o.status = 'completed'" -> "orders.status = 'completed'"
                    $resolvedFilter = preg_replace_callback('/([a-zA-Z0-9_]+)\.([a-zA-Z0-9_]+)/', function ($m) use ($aliases) {
                        $alias = strtolower($m[1]);
                        $col = $m[2];
                        return ($aliases[$alias] ?? $alias) . '.' . $col;
                    }, $trimmed);

                    $filters[] = $resolvedFilter;
                }
            }
        }

        return $filters;
    }

    /**
     * Extract GROUP BY fields.
     *
     * @param string $sql
     * @param array<string, string> $aliases
     * @return list<string>
     */
    public function extractGrouping(string $sql, array $aliases): array
    {
        $grouping = [];

        if (preg_match('/\bgroup\s+by\s+(.*?)(?:\bhaving\b|\border\s+by\b|\blimit\b|$)/is', $sql, $groupMatch)) {
            $raw = explode(',', trim($groupMatch[1]));
            foreach ($raw as $item) {
                $trimmed = trim($item, " \r\n\t");
                if (!empty($trimmed)) {
                    $resolved = preg_replace_callback('/([a-zA-Z0-9_]+)\.([a-zA-Z0-9_]+)/', function ($m) use ($aliases) {
                        $alias = strtolower($m[1]);
                        $col = $m[2];
                        return ($aliases[$alias] ?? $alias) . '.' . $col;
                    }, $trimmed);
                    $grouping[] = $resolved;
                }
            }
        }

        return $grouping;
    }

    /**
     * Extract explicit aggregations with their function and resolved target column.
     * e.g. "SUM(orders.total_amount)" or "COUNT(*)"
     *
     * @param string $sql
     * @param array<string, string> $aliases
     * @return list<string>
     */
    public function extractAggregations(string $sql, array $aliases): array
    {
        $aggregations = [];

        // Match SUM(col), AVG(col), COUNT(col), MIN(col), MAX(col)
        $pattern = '/\b(sum|avg|count|min|max)\s*\(\s*(distinct\s+)?([a-zA-Z0-9_.*]+(?:\.[a-zA-Z0-9_*]+)?)\s*\)/i';
        if (preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $func = strtoupper($match[1]);
                $distinct = !empty($match[2]) ? 'DISTINCT ' : '';
                $target = trim($match[3]);

                // Resolve table alias if present (e.g. o.total -> orders.total)
                if (str_contains($target, '.')) {
                    [$tblAlias, $col] = explode('.', $target, 2);
                    $tbl = $aliases[strtolower($tblAlias)] ?? strtolower($tblAlias);
                    $target = "{$tbl}.{$col}";
                }

                $aggregations[] = "{$func}({$distinct}{$target})";
            }
        }

        return array_values(array_unique($aggregations));
    }

    /**
     * Determine semantic grain of the query result.
     *
     * @param list<string> $tables
     * @param list<string> $joins
     * @param list<string> $grouping
     * @param list<string> $aggregations
     * @param array $relationships
     * @return string
     */
    public function determineGrain(
        array $tables,
        array $joins,
        array $grouping,
        array $aggregations,
        array $relationships
    ): string {
        // If query has GROUP BY, the grain is primarily defined by the grouped dimensions
        if (!empty($grouping)) {
            $formattedGroups = array_map(function ($g) {
                if (str_contains($g, '.')) {
                    $parts = explode('.', $g);
                    return Str::singular(Str::studly($parts[0])) . ' ' . Str::studly($parts[1]);
                }
                return Str::studly($g);
            }, $grouping);

            return implode(', ', $formattedGroups) . ' (Grouped)';
        }

        // If it's a pure aggregate query (e.g. SUM or COUNT without GROUP BY)
        if (!empty($aggregations) && count($aggregations) > 0 && empty($grouping)) {
            // Check if tables are joined
            if (count($tables) > 1) {
                return $this->formatTableHierarchyGrain($tables, $relationships);
            }
            return 'Aggregated (' . Str::singular(Str::studly($tables[0] ?? 'Metric')) . ')';
        }

        // Single table
        if (count($tables) === 1) {
            return Str::singular(Str::studly($tables[0]));
        }

        // Multiple tables: derive hierarchy grain (e.g. Order → Order Item)
        return $this->formatTableHierarchyGrain($tables, $relationships);
    }

    /**
     * Format table hierarchy grain along 1:N relationships.
     * e.g. ['orders', 'order_items'] -> 'Order → Order Item'
     *
     * @param list<string> $tables
     * @param array $relationships
     * @return string
     */
    protected function formatTableHierarchyGrain(array $tables, array $relationships): string
    {
        // Sort tables topologically based on parent -> child (1:N) relationships
        // In relationships: 'to_table' is parent (1), 'from_table' is child (N)
        $ordered = [];

        // Build parent-to-child map
        $parentOf = [];
        foreach ($relationships as $rel) {
            $parent = strtolower($rel['to_table'] ?? '');
            $child = strtolower($rel['from_table'] ?? '');
            if (in_array($parent, $tables, true) && in_array($child, $tables, true)) {
                $parentOf[$parent][] = $child;
            }
        }

        // Find root parent(s) in $tables
        foreach ($tables as $t) {
            $isChild = false;
            foreach ($relationships as $rel) {
                if (strtolower($rel['from_table'] ?? '') === $t && in_array(strtolower($rel['to_table'] ?? ''), $tables, true)) {
                    $isChild = true;
                    break;
                }
            }
            if (!$isChild && !in_array($t, $ordered, true)) {
                $ordered[] = $t;
            }
        }

        // Append children
        foreach ($ordered as $parent) {
            if (isset($parentOf[$parent])) {
                foreach ($parentOf[$parent] as $child) {
                    if (!in_array($child, $ordered, true)) {
                        $ordered[] = $child;
                    }
                }
            }
        }

        // Add any remaining tables
        foreach ($tables as $t) {
            if (!in_array($t, $ordered, true)) {
                $ordered[] = $t;
            }
        }

        $formatted = array_map(function ($tbl) {
            return Str::singular(Str::headline($tbl));
        }, $ordered);

        return implode(' → ', $formatted);
    }

    /**
     * Detect multiplication risk (fan-out aggregation trap).
     *
     * Occurs when a query joins parent table A (1) to child table B (N),
     * and performs SUM/AVG on a column from parent table A.
     *
     * @param list<string> $tables
     * @param list<string> $joins
     * @param list<string> $aggregations
     * @param list<string> $grouping
     * @param array $relationships
     * @return array{detected: bool, warning: string, details: string, recommendation: string}|null
     */
    public function detectMultiplicationRisk(
        array $tables,
        array $joins,
        array $aggregations,
        array $grouping,
        array $relationships
    ): ?array {
        if (count($tables) < 2 || empty($aggregations)) {
            return null;
        }

        // Identify 1:N pairs in the query: to_table (parent, 1) -> from_table (child, N)
        foreach ($relationships as $rel) {
            $parentTable = strtolower($rel['to_table'] ?? '');
            $childTable = strtolower($rel['from_table'] ?? '');

            if (in_array($parentTable, $tables, true) && in_array($childTable, $tables, true)) {
                // Check if any aggregation is targeting a column on the parent table
                foreach ($aggregations as $agg) {
                    // Match SUM(parentTable.col) or AVG(parentTable.col)
                    if (preg_match('/^(SUM|AVG)\s*\(\s*(?:DISTINCT\s+)?' . preg_quote($parentTable, '/') . '\.([a-zA-Z0-9_]+)\s*\)/i', $agg, $m)) {
                        $func = strtoupper($m[1]);
                        $col = $m[2];

                        // If it's COUNT(DISTINCT parent.id), that is safe and does not multiply.
                        // But SUM or AVG on a parent metric (e.g. orders.total or orders.total_amount) multiplies!
                        if (str_contains(strtoupper($agg), 'DISTINCT') && $func === 'COUNT') {
                            continue;
                        }

                        $parentDisplay = Str::singular($parentTable);
                        $childDisplay = $childTable;

                        return [
                            'detected' => true,
                            'warning' => "{$childDisplay} contains multiple rows per {$parentDisplay}.",
                            'details' => "Performing {$func}({$parentTable}.{$col}) across joined '{$childTable}' multiplies {$parentDisplay} values by the number of line items, causing inflated totals.",
                            'recommendation' => "Aggregate at the '{$parentTable}' level directly, aggregate child-level columns (such as {$childTable}.total_price), or use a CTE / subquery before joining.",
                        ];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Identify potential complexity risks (e.g. unbounded SELECT *, excessive joins, Cartesian products).
     *
     * @param string $sql
     * @param list<string> $tables
     * @param list<string> $joins
     * @param list<string> $filters
     * @return array{risk_level: 'low'|'medium'|'high', risk_reasons: list<string>}
     */
    public function detectComplexityRisk(string $sql, array $tables, array $joins, array $filters): array
    {
        $reasons = [];

        $hasSelectAll = (bool) preg_match('/\bSELECT\s+(?:DISTINCT\s+)?(?:\*|[a-zA-Z0-9_]+\.\*)/i', $sql);
        $hasLimit = (bool) preg_match('/\bLIMIT\s+\d+/i', $sql);
        $hasCrossJoin = (bool) preg_match('/\bCROSS\s+JOIN\b/i', $sql);
        $hasCommaJoin = (bool) preg_match('/\bFROM\s+[`"]?[a-zA-Z0-9_]+[`"]?\s*,\s*[`"]?[a-zA-Z0-9_]+[`"]?/i', $sql);

        // 1. Unbounded SELECT *
        if ($hasSelectAll && !$hasLimit) {
            $reasons[] = 'Unbounded SELECT * without a LIMIT clause may retrieve thousands of columns and rows, impacting browser performance.';
        }

        // 2. Cartesian product / cross join
        if ($hasCrossJoin || $hasCommaJoin) {
            $reasons[] = 'Potential Cartesian product (CROSS JOIN) detected without explicit ON predicates, which can cause combinatorial row multiplication.';
        }

        // 3. Excessive joins (>4 joins)
        if (count($joins) > 4) {
            $joinCount = count($joins);
            $reasons[] = "Query joins {$joinCount} tables, which may consume significant database memory and execution time.";
        }

        // 4. Multi-table query with zero filters and no limit
        if (count($tables) >= 2 && empty($filters) && !$hasLimit) {
            $reasons[] = 'Multi-table join query contains no WHERE filter constraints or LIMIT clause.';
        }

        // Compute risk level
        $riskLevel = 'low';
        if ($hasCrossJoin || $hasCommaJoin || (count($joins) > 4 && empty($filters))) {
            $riskLevel = 'high';
        } elseif ($hasSelectAll && !$hasLimit || count($joins) > 3 || (count($tables) >= 2 && empty($filters))) {
            $riskLevel = 'medium';
        }

        return [
            'risk_level' => $riskLevel,
            'risk_reasons' => $reasons,
        ];
    }

    /**
     * Default schema relationships for the demo database.
     */
    protected function getDefaultRelationships(): array
    {
        return [
            ['from' => 'orders.customer_id', 'to' => 'customers.id', 'from_table' => 'orders', 'from_column' => 'customer_id', 'to_table' => 'customers', 'to_column' => 'id', 'label' => 'belongs to customer'],
            ['from' => 'order_items.order_id', 'to' => 'orders.id', 'from_table' => 'order_items', 'from_column' => 'order_id', 'to_table' => 'orders', 'to_column' => 'id', 'label' => 'belongs to order'],
            ['from' => 'order_items.product_id', 'to' => 'products.id', 'from_table' => 'order_items', 'from_column' => 'product_id', 'to_table' => 'products', 'to_column' => 'id', 'label' => 'contains product'],
        ];
    }
}
