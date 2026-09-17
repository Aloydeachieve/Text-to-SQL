<?php

namespace App\Services;

use App\Services\Ai\GeminiAiService;
use App\Services\DatabaseSchemaService;
use Illuminate\Support\Facades\Log;

class SqlSemanticValidator
{
    protected DatabaseSchemaService $schemaService;
    protected ?GeminiAiService $geminiService;
    protected QueryInterpretationService $interpretationService;

    public function __construct(
        DatabaseSchemaService $schemaService,
        ?GeminiAiService $geminiService = null,
        ?QueryInterpretationService $interpretationService = null
    ) {
        $this->schemaService = $schemaService;
        $this->geminiService = $geminiService;
        $this->interpretationService = $interpretationService ?? app(QueryInterpretationService::class);
    }

    /**
     * Validate whether the generated SQL accurately reflects the natural language query intent
     * and extract complete query interpretation (tables, joins, grain, filters, aggregations, multiplication risk).
     *
     * @param string $question
     * @param string $sql
     * @param string|null $schemaContext
     * @param array<string, list<string>>|null $schema
     * @param string $driver
     * @param array|null $schemaDetails
     * @return array{
     *     valid: bool,
     *     score: float,
     *     reason: ?string,
     *     interpretation: string,
     *     tables: list<string>,
     *     operations: list<string>,
     *     filters: list<string>,
     *     grouping: list<string>,
     *     ordering: list<string>,
     *     joins: list<string>,
     *     grain: string,
     *     aggregations: list<string>,
     *     multiplication_risk: ?array{
     *         detected: bool,
     *         warning: string,
     *         details: string,
     *         recommendation: string
     *     }
     * }
     */
    public function validate(
        string $question,
        string $sql,
        ?string $schemaContext = null,
        ?array $schema = null,
        string $driver = 'mysql',
        ?array $schemaDetails = null,
        ?int $companyId = null
    ): array {
        $normalizedSql = trim($sql);
        $schemaDetails = $schemaDetails ?? $this->schemaService->getSchemaDetails();
        $interpretationData = $this->interpretationService->interpret($normalizedSql, $schemaDetails);
        $structure = $this->extractStructure($normalizedSql);

        $baseDetails = [
            'tables' => !empty($interpretationData['tables']) ? $interpretationData['tables'] : $structure['tables'],
            'operations' => $structure['operations'],
            'filters' => !empty($interpretationData['filters']) ? $interpretationData['filters'] : $structure['filters'],
            'grouping' => $structure['grouping'],
            'ordering' => $structure['ordering'],
            'joins' => $interpretationData['joins'],
            'grain' => $interpretationData['grain'],
            'aggregations' => $interpretationData['aggregations'],
            'multiplication_risk' => $interpretationData['multiplication_risk'],
            'risk_level' => $interpretationData['risk_level'] ?? 'low',
            'risk_reasons' => $interpretationData['risk_reasons'] ?? [],
        ];

        // Evaluate Phase 9 business semantic intelligence & table classifications
        $semantics = $this->evaluateBusinessSemantics($question, $normalizedSql, $baseDetails, $companyId);
        $baseDetails = array_merge($baseDetails, [
            'semantic_intelligence' => $semantics,
            'metric' => $semantics['metric'],
            'metric_id' => $semantics['metric_id'],
            'metric_source' => $semantics['metric_source'],
            'metric_column' => $semantics['metric_column'],
            'aggregation' => $semantics['aggregation'],
            'required_filters' => $semantics['required_filters'],
            'source_of_truth' => $semantics['source_of_truth'],
            'semantic_confidence' => $semantics['confidence'],
            'semantic_warnings' => $semantics['semantic_warnings'],
        ]);

        // 1. Run deterministic checks to detect clear intent mismatches or well-known query patterns
        $deterministicResult = $this->evaluateDeterministicIntent($question, $normalizedSql, $structure, $schema);

        // If a deterministic mismatch was explicitly identified, fail closed immediately
        if ($deterministicResult !== null && !$deterministicResult['valid']) {
            return array_merge($deterministicResult, $baseDetails);
        }

        // 2. If AI provider is Gemini and gemini service is available, perform AI-assisted intent verification
        $driver = config('services.ai.driver', 'local');
        if ($driver === 'gemini' && $this->geminiService !== null && !empty(config('services.gemini.key'))) {
            try {
                $aiResult = $this->geminiService->verifyIntent($question, $normalizedSql, $structure, $schemaContext);
                return array_merge([
                    'valid' => (bool)$aiResult['valid'],
                    'score' => (float)$aiResult['score'],
                    'reason' => $aiResult['reason'],
                    'interpretation' => $aiResult['interpretation'],
                ], $baseDetails);
            } catch (\Exception $e) {
                Log::error('Semantic verification exception', [
                    'question' => $question,
                    'sql' => $normalizedSql,
                    'error' => $e->getMessage()
                ]);

                return array_merge([
                    'valid' => false,
                    'score' => 0.0,
                    'reason' => 'Semantic verification failed: ' . $e->getMessage(),
                    'interpretation' => 'Semantic verification could not be completed.',
                ], $baseDetails);
            }
        }

        // 3. If in local/rule-based mode or deterministic match succeeded
        if ($deterministicResult !== null) {
            return array_merge($deterministicResult, $baseDetails);
        }

        // Fallback default for queries without explicit deterministic match in local mode
        return array_merge([
            'valid' => true,
            'score' => 0.90,
            'reason' => null,
            'interpretation' => 'Executes query for ' . implode(', ', $baseDetails['tables']),
        ], $baseDetails);
    }

    /**
     * Extract syntactic and structural elements from the SQL query.
     *
     * @param string $sql
     * @return array{
     *     tables: list<string>,
     *     operations: list<string>,
     *     filters: list<string>,
     *     grouping: list<string>,
     *     ordering: list<string>
     * }
     */
    public function extractStructure(string $sql): array
    {
        // Strip string literals to prevent matching words inside quotes
        $sqlWithoutStrings = preg_replace('/([\'"])(.*?)\1/', '', $sql) ?? $sql;

        // 1. Extract referenced tables
        preg_match_all('/\b(?:from|join)\s+[`"]?([a-zA-Z0-9_]+)[`"]?/i', $sqlWithoutStrings, $tableMatches);
        $tables = array_values(array_unique(array_map('strtolower', $tableMatches[1] ?? [])));

        // 2. Extract operations
        $operations = [];
        if (preg_match('/\bcount\s*\(/i', $sqlWithoutStrings)) {
            $operations[] = 'COUNT';
        }
        if (preg_match('/\bsum\s*\(/i', $sqlWithoutStrings)) {
            $operations[] = 'SUM';
        }
        if (preg_match('/\bavg\s*\(/i', $sqlWithoutStrings)) {
            $operations[] = 'AVG';
        }
        if (preg_match('/\bmin\s*\(/i', $sqlWithoutStrings)) {
            $operations[] = 'MIN';
        }
        if (preg_match('/\bmax\s*\(/i', $sqlWithoutStrings)) {
            $operations[] = 'MAX';
        }
        if (preg_match('/\bjoin\b/i', $sqlWithoutStrings)) {
            $operations[] = 'JOIN';
        }
        if (preg_match('/\bgroup\s+by\b/i', $sqlWithoutStrings)) {
            $operations[] = 'GROUP BY';
        }
        if (preg_match('/\border\s+by\b.*?\bdesc\b/i', $sqlWithoutStrings)) {
            $operations[] = 'ORDER BY DESC';
        } elseif (preg_match('/\border\s+by\b/i', $sqlWithoutStrings)) {
            $operations[] = 'ORDER BY';
        }
        if (preg_match('/\blimit\b/i', $sqlWithoutStrings)) {
            $operations[] = 'LIMIT';
        }
        if (preg_match('/\bdate_format\s*\(/i', $sqlWithoutStrings)) {
            $operations[] = 'DATE_FORMAT';
        }
        if (preg_match('/\bdate_trunc\s*\(/i', $sqlWithoutStrings)) {
            $operations[] = 'DATE_TRUNC';
        }
        if (preg_match('/\bextract\s*\(/i', $sqlWithoutStrings)) {
            $operations[] = 'EXTRACT';
        }

        // 3. Extract filters (WHERE clause)
        $filters = [];
        if (preg_match('/\bwhere\s+(.*?)(?:\bgroup\s+by\b|\border\s+by\b|\blimit\b|$)/is', $sqlWithoutStrings, $whereMatch)) {
            $rawFilters = preg_split('/\s+(?:and|or)\s+/i', trim($whereMatch[1]));
            if ($rawFilters) {
                foreach ($rawFilters as $filter) {
                    $trimmed = trim($filter, " ()");
                    if (!empty($trimmed)) {
                        $filters[] = $trimmed;
                    }
                }
            }
        }

        // 4. Extract grouping (GROUP BY clause)
        $grouping = [];
        if (preg_match('/\bgroup\s+by\s+(.*?)(?:\bhaving\b|\border\s+by\b|\blimit\b|$)/is', $sqlWithoutStrings, $groupMatch)) {
            $rawGrouping = explode(',', trim($groupMatch[1]));
            foreach ($rawGrouping as $group) {
                $trimmed = trim($group);
                if (!empty($trimmed)) {
                    $grouping[] = $trimmed;
                }
            }
        }

        // 5. Extract ordering (ORDER BY clause)
        $ordering = [];
        if (preg_match('/\border\s+by\s+(.*?)(?:\blimit\b|$)/is', $sqlWithoutStrings, $orderMatch)) {
            $rawOrdering = explode(',', trim($orderMatch[1]));
            foreach ($rawOrdering as $order) {
                $trimmed = trim($order);
                if (!empty($trimmed)) {
                    $ordering[] = $trimmed;
                }
            }
        }

        return [
            'tables' => $tables,
            'operations' => $operations,
            'filters' => $filters,
            'grouping' => $grouping,
            'ordering' => $ordering,
        ];
    }

    /**
     * Statically evaluate the alignment of the query structure against question intent.
     *
     * @param string $question
     * @param string $sql
     * @param array $structure
     * @param array<string, list<string>>|null $schema
     * @return array{valid: bool, score: float, reason: ?string, interpretation: string}|null
     */
    protected function evaluateDeterministicIntent(string $question, string $sql, array $structure, ?array $schema = null): ?array
    {
        if ($schema !== null) {
            $schemaTables = array_map('strtolower', array_keys($schema));
            // If customer schema doesn't contain demo tables, skip demo-specific deterministic intent checks
            if (!in_array('customers', $schemaTables) && !in_array('orders', $schemaTables) && !in_array('products', $schemaTables)) {
                return null;
            }
        }

        $qLower = strtolower($question);
        $tables = $structure['tables'];
        $operations = $structure['operations'];
        $grouping = $structure['grouping'];
        $ordering = $structure['ordering'];

        // Pattern 1: Question asks for customer identification/ranking (e.g. "Which customers placed the most orders?")
        if (str_contains($qLower, 'customer') && (str_contains($qLower, 'most') || str_contains($qLower, 'highest') || str_contains($qLower, 'top')) && str_contains($qLower, 'order')) {
            // Check if the query fails to identify or group customers
            $groupsCustomers = false;
            foreach ($grouping as $grp) {
                if (preg_match('/(?:customer|c\.id|c\.name)/i', $grp)) {
                    $groupsCustomers = true;
                }
            }

            $ordersDescending = in_array('ORDER BY DESC', $operations);

            // Mismatch: Query only counts orders overall without identifying/grouping individual customers
            if (!in_array('customers', $tables) && !$groupsCustomers) {
                return [
                    'valid' => false,
                    'score' => 0.31,
                    'reason' => 'The query calculates total orders but does not identify or rank individual customers.',
                    'interpretation' => 'Calculate the total number of orders.',
                ];
            }

            // Mismatch: References customers or orders but lacks grouping or ranking
            if (!$groupsCustomers || !$ordersDescending) {
                return [
                    'valid' => false,
                    'score' => 0.45,
                    'reason' => 'The query does not group by customer or rank order counts in descending order.',
                    'interpretation' => 'Selects customer or order records without ranking by order count.',
                ];
            }

            // Valid customer ranking query
            return [
                'valid' => true,
                'score' => 0.96,
                'reason' => null,
                'interpretation' => 'Find customers, count their orders, and rank them by order count.',
            ];
        }

        // Pattern 2: Customer count ("How many customers do we have?")
        if (str_contains($qLower, 'how many') && str_contains($qLower, 'customer')) {
            if (in_array('customers', $tables) && in_array('COUNT', $operations)) {
                return [
                    'valid' => true,
                    'score' => 0.98,
                    'reason' => null,
                    'interpretation' => 'Count the total number of records in the customers table.',
                ];
            }

            if (!in_array('customers', $tables)) {
                return [
                    'valid' => false,
                    'score' => 0.20,
                    'reason' => 'The query does not query the customers table.',
                    'interpretation' => 'Queries ' . implode(', ', $tables) . ' instead of customers.',
                ];
            }
        }

        // Pattern 3: Top-selling products / revenue by product
        if (str_contains($qLower, 'product') && (str_contains($qLower, 'top') || str_contains($qLower, 'most revenue') || str_contains($qLower, 'best'))) {
            $groupsProducts = false;
            foreach ($grouping as $grp) {
                if (preg_match('/(?:product|p\.id|p\.name)/i', $grp)) {
                    $groupsProducts = true;
                }
            }

            if (in_array('products', $tables) && in_array('SUM', $operations) && $groupsProducts && in_array('ORDER BY DESC', $operations)) {
                return [
                    'valid' => true,
                    'score' => 0.97,
                    'reason' => null,
                    'interpretation' => 'Join products with order items, aggregate revenue, and rank products in descending order.',
                ];
            }

            if (!in_array('products', $tables) || !$groupsProducts) {
                return [
                    'valid' => false,
                    'score' => 0.35,
                    'reason' => 'The query does not group and aggregate revenue by product.',
                    'interpretation' => 'Calculates records without grouping and ranking products by revenue.',
                ];
            }
        }

        // Pattern 4: Monthly revenue
        if (str_contains($qLower, 'revenue') && (str_contains($qLower, 'month') || str_contains($qLower, 'monthly'))) {
            if ((in_array('orders', $tables) || in_array('order_items', $tables)) && in_array('SUM', $operations) && (in_array('DATE_FORMAT', $operations) || in_array('DATE_TRUNC', $operations) || !empty($grouping))) {
                return [
                    'valid' => true,
                    'score' => 0.98,
                    'reason' => null,
                    'interpretation' => 'Extracts year and month from orders, sums revenue, and groups chronologically by month.',
                ];
            }
        }

        // Pattern 5: Average order value
        if (str_contains($qLower, 'average') && str_contains($qLower, 'order')) {
            if (in_array('orders', $tables) && in_array('AVG', $operations)) {
                return [
                    'valid' => true,
                    'score' => 0.99,
                    'reason' => null,
                    'interpretation' => 'Calculates the average total amount across all placed orders.',
                ];
            }
        }

        return null;
    }

    /**
     * Evaluate company semantic metric compliance and table classification warnings.
     *
     * @param string $question
     * @param string $sql
     * @param array $details
     * @param int|null $companyId
     * @return array{
     *     metric: ?string,
     *     metric_id: ?int,
     *     metric_source: ?string,
     *     metric_column: ?string,
     *     aggregation: ?string,
     *     required_filters: list<string>,
     *     source_of_truth: bool,
     *     confidence: 'HIGH'|'MEDIUM'|'LOW',
     *     semantic_warnings: list<array{type: string, severity: 'warning'|'info'|'critical', table?: string, message: string, suggestion?: string}>
     * }
     */
    public function evaluateBusinessSemantics(
        string $question,
        string $sql,
        array $details,
        ?int $companyId = null
    ): array {
        $result = [
            'metric' => null,
            'metric_id' => null,
            'metric_source' => null,
            'metric_column' => null,
            'aggregation' => null,
            'required_filters' => [],
            'source_of_truth' => false,
            'confidence' => 'HIGH',
            'semantic_warnings' => [],
        ];

        if (!$companyId) {
            return $result;
        }

        try {
            $semanticService = app(\App\Services\SemanticContextService::class);
            $classifications = $semanticService->getTableClassifications($companyId);
            $metrics = $semanticService->findRelevantMetrics($question, $companyId);
            $tablesInQuery = array_map('strtolower', $details['tables'] ?? []);

            // 1. Check Table Classifications for warnings
            foreach ($tablesInQuery as $tableName) {
                if (isset($classifications[$tableName])) {
                    $info = $classifications[$tableName];
                    $class = strtolower($info['classification'] ?? 'business');

                    if ($class === 'staging') {
                        $result['semantic_warnings'][] = [
                            'type' => 'staging_source_warning',
                            'severity' => 'warning',
                            'table' => $tableName,
                            'message' => "Table '{$tableName}' is classified as [staging]. Data may be unverified, incomplete, or temporary.",
                            'suggestion' => "Verify if production business tables should be queried instead.",
                        ];
                        $result['confidence'] = 'MEDIUM';
                    } elseif ($class === 'archive') {
                        $result['semantic_warnings'][] = [
                            'type' => 'archive_source_warning',
                            'severity' => 'warning',
                            'table' => $tableName,
                            'message' => "Table '{$tableName}' is classified as [archive]. Data reflects historical records and may not be current.",
                            'suggestion' => "Ensure historical analysis was intended.",
                        ];
                        $result['confidence'] = 'MEDIUM';
                    } elseif ($class === 'test') {
                        $result['semantic_warnings'][] = [
                            'type' => 'test_source_warning',
                            'severity' => 'critical',
                            'table' => $tableName,
                            'message' => "Table '{$tableName}' is classified as [test]. Results do not represent genuine business operations.",
                            'suggestion' => "Do not use test tables for official reporting.",
                        ];
                        $result['confidence'] = 'LOW';
                    }
                }
            }

            // 2. Business Metric Matching & Verification
            if ($metrics->isNotEmpty()) {
                $metric = $metrics->first();
                $result['metric'] = $metric->name;
                $result['metric_id'] = $metric->id;
                $result['metric_source'] = $metric->source_table;
                $result['metric_column'] = $metric->source_column;
                $result['aggregation'] = $metric->aggregation;
                $result['source_of_truth'] = (bool)$metric->is_source_of_truth;

                if (!empty($metric->filter_condition)) {
                    $result['required_filters'][] = $metric->filter_condition;
                }

                $sourceTableLower = strtolower($metric->source_table);
                $sourceColLower = strtolower($metric->source_column);
                $sqlLower = strtolower($sql);

                // A) Check source table compliance
                if (!in_array($sourceTableLower, $tablesInQuery, true)) {
                    $result['semantic_warnings'][] = [
                        'type' => 'metric_source_mismatch',
                        'severity' => 'warning',
                        'message' => "Business metric '{$metric->name}' canonically originates from table '{$metric->source_table}', but the query targets [" . implode(', ', $tablesInQuery) . "].",
                        'suggestion' => "Update query to source from '{$metric->source_table}'.",
                    ];
                    $result['confidence'] = 'MEDIUM';
                    $result['source_of_truth'] = false;
                }

                // B) Check source column compliance
                if (!str_contains($sqlLower, $sourceColLower)) {
                    $result['semantic_warnings'][] = [
                        'type' => 'metric_column_mismatch',
                        'severity' => 'warning',
                        'message' => "Business metric '{$metric->name}' is defined on column '{$metric->source_column}', but this column is not referenced in the query.",
                        'suggestion' => "Ensure '{$metric->source_column}' is used for calculation.",
                    ];
                    $result['confidence'] = 'MEDIUM';
                    $result['source_of_truth'] = false;
                }

                // C) Check aggregation compliance
                $requiredAgg = strtoupper($metric->aggregation ?? 'SUM');
                if ($requiredAgg !== 'NONE') {
                    $hasAgg = in_array($requiredAgg, array_map('strtoupper', $details['operations'] ?? []), true)
                        || collect($details['aggregations'] ?? [])->contains(fn($agg) => str_starts_with(strtoupper($agg), "{$requiredAgg}(") || str_contains(strtoupper($agg), "{$requiredAgg}("))
                        || (bool)preg_match('/\b' . preg_quote($requiredAgg, '/') . '\s*\(/i', $sql);

                    if (!$hasAgg) {
                        $result['semantic_warnings'][] = [
                            'type' => 'aggregation_mismatch',
                            'severity' => 'warning',
                            'message' => "Business metric '{$metric->name}' requires aggregation {$requiredAgg}(), but query does not use it.",
                            'suggestion' => "Use {$requiredAgg}() aggregation function.",
                        ];
                        $result['confidence'] = 'MEDIUM';
                    }
                }

                // D) Check required filter compliance
                if (!empty($metric->filter_condition)) {
                    $cleanFilter = preg_replace('/[^a-z0-9_]+/i', ' ', strtolower($metric->filter_condition));
                    $filterWords = array_filter(explode(' ', $cleanFilter));
                    $allWordsPresent = true;
                    foreach ($filterWords as $word) {
                        if (strlen($word) >= 2 && !str_contains($sqlLower, $word)) {
                            $allWordsPresent = false;
                            break;
                        }
                    }

                    if (!$allWordsPresent) {
                        $result['semantic_warnings'][] = [
                            'type' => 'required_filter_missing',
                            'severity' => 'warning',
                            'message' => "Business metric '{$metric->name}' requires filter condition: \"{$metric->filter_condition}\", which was not detected in the WHERE clause.",
                            'suggestion' => "Add \"WHERE {$metric->filter_condition}\" to ensure metric accuracy.",
                        ];
                        $result['confidence'] = 'MEDIUM';
                        $result['source_of_truth'] = false;
                    }
                }
            }

            $hasCritical = collect($result['semantic_warnings'])->contains('severity', 'critical');
            if ($hasCritical) {
                $result['confidence'] = 'LOW';
            }
        } catch (\Throwable $e) {
            // Fail gracefully on error
        }

        return $result;
    }
}
