<?php

namespace App\Services;

use App\Models\DatabaseConnection;
use App\Models\QueryLog;
use App\Models\SavedQuery;
use Throwable;

class SavedQueryExecutionService
{
    protected SqlGuardrailService $guardrailService;
    protected SqlSchemaValidator $schemaValidator;
    protected SqlExecutorService $executorService;
    protected DatabaseConnectionManager $connectionManager;
    protected SchemaIntrospectionService $introspectionService;
    protected DatabaseSchemaService $defaultSchemaService;
    protected DashboardFilterService $filterService;
    protected DashboardCacheService $cacheService;
    protected QueryInterpretationService $interpretationService;

    public function __construct(
        SqlGuardrailService $guardrailService,
        SqlSchemaValidator $schemaValidator,
        SqlExecutorService $executorService,
        DatabaseConnectionManager $connectionManager,
        SchemaIntrospectionService $introspectionService,
        DatabaseSchemaService $defaultSchemaService,
        ?DashboardFilterService $filterService = null,
        ?DashboardCacheService $cacheService = null,
        ?QueryInterpretationService $interpretationService = null
    ) {
        $this->guardrailService = $guardrailService;
        $this->schemaValidator = $schemaValidator;
        $this->executorService = $executorService;
        $this->connectionManager = $connectionManager;
        $this->introspectionService = $introspectionService;
        $this->defaultSchemaService = $defaultSchemaService;
        $this->filterService = $filterService ?? app(DashboardFilterService::class);
        $this->cacheService = $cacheService ?? app(DashboardCacheService::class);
        $this->interpretationService = $interpretationService ?? app(QueryInterpretationService::class);
    }

    /**
     * Execute a saved query through the comprehensive security and validation pipeline.
     * Supports safe date parameterization, 5-minute caching, and query correctness preservation.
     *
     * @param SavedQuery $savedQuery
     * @param int|null $userId
     * @param string $source ('saved_query' or 'dashboard')
     * @param string|null $customSql
     * @param array $filterParams Date filter parameters ('date_preset', 'date_from', 'date_to')
     * @param bool $bypassCache Force live DB execution bypassing cache
     * @return array
     */
    public function execute(
        SavedQuery $savedQuery,
        ?int $userId,
        string $source = 'saved_query',
        ?string $customSql = null,
        array $filterParams = [],
        bool $bypassCache = false
    ): array {
        $companyId = $savedQuery->company_id;
        $sql = $customSql ?: $savedQuery->sql;
        $databaseConnection = null;
        $runtimeConnectionName = null;
        $driver = $savedQuery->dialect ?? 'mysql';
        $customerSchema = null;
        $normalizedSchema = null;

        // 0. Cache Check for Dashboard Executions
        $cacheKey = $this->cacheService->getCacheKey(
            $companyId,
            $savedQuery->database_connection_id,
            $savedQuery->id,
            $savedQuery->updated_at?->toIso8601String(),
            $filterParams
        );

        if ($source === 'dashboard' && !$bypassCache) {
            $cached = $this->cacheService->get($cacheKey);
            if ($cached !== null) {
                $cached['cache_hit'] = true;
                return $cached;
            }
        }

        // 1. Connection Verification: Guard against deleted/disabled customer database connections
        if (!$savedQuery->is_demo || $savedQuery->database_connection_id !== null) {
            if ($savedQuery->database_connection_id === null) {
                // Connection was deleted/nullified
                return [
                    'success' => false,
                    'message' => "This saved query's database connection is no longer available.",
                    'error' => "This saved query's database connection is no longer available.",
                    'status_code' => 422,
                    'execution' => [
                        'success' => false,
                        'error' => "This saved query's database connection is no longer available.",
                        'time_ms' => 0,
                        'results' => [],
                    ],
                ];
            }

            $databaseConnection = DatabaseConnection::where('company_id', $companyId)
                ->where('id', $savedQuery->database_connection_id)
                ->first();

            if (!$databaseConnection) {
                return [
                    'success' => false,
                    'message' => "This saved query's database connection is no longer available.",
                    'error' => "This saved query's database connection is no longer available.",
                    'status_code' => 422,
                    'execution' => [
                        'success' => false,
                        'error' => "This saved query's database connection is no longer available.",
                        'time_ms' => 0,
                        'results' => [],
                    ],
                ];
            }

            $driver = strtolower($databaseConnection->driver);
            $runtimeConnectionName = $this->connectionManager->getConnectionName($databaseConnection);

            try {
                $normalizedSchema = $this->introspectionService->introspect($databaseConnection);
                $customerSchema = $this->introspectionService->getTablesAndColumns($normalizedSchema);
            } catch (Throwable $e) {
                return [
                    'success' => false,
                    'question' => $savedQuery->natural_language_question,
                    'sql' => $sql,
                    'guardrails' => [
                        'allowed' => false,
                        'reason' => 'Failed to introspect customer database schema.'
                    ],
                    'schema_validation' => null,
                    'semantic_validation' => null,
                    'execution' => [
                        'success' => false,
                        'error' => 'Database connection error: Unable to introspect schema.',
                        'time_ms' => 0,
                        'results' => []
                    ],
                    'confidence' => 1.0,
                    'explanation' => 'Execution failed: unable to introspect customer database schema.',
                    'visualization_type' => $savedQuery->result_visualization_type,
                    'status_code' => 422,
                ];
            }
        } else {
            // Demo database mode
            $normalizedSchema = $this->defaultSchemaService->getSchemaDetails();
            $customerSchema = $this->defaultSchemaService->getTablesAndColumns();
        }

        // 2. Safe Date Parameterization Engine
        $bindings = [];
        $filterApplied = false;
        $filterStatus = 'none';
        $filterMessage = null;

        if (!empty($filterParams['date_preset']) || !empty($filterParams['date_from']) || !empty($filterParams['date_to'])) {
            try {
                $dateRange = $this->filterService->resolveDateRange(
                    $filterParams['date_preset'] ?? null,
                    $filterParams['date_from'] ?? null,
                    $filterParams['date_to'] ?? null
                );

                if ($dateRange !== null) {
                    $compatibility = $this->filterService->analyzeFilterCompatibility($savedQuery, $customerSchema, $driver);
                    if ($compatibility['supported']) {
                        $filterResult = $this->filterService->applyDateFilter($sql, $compatibility, $dateRange['from'], $dateRange['to'], $driver);
                        $sql = $filterResult['sql'];
                        $bindings = $filterResult['bindings'];
                        $filterApplied = true;
                        $filterStatus = 'applied';
                        $filterMessage = $filterResult['filter_message'];
                    } else {
                        $filterApplied = false;
                        $filterStatus = 'unsupported';
                        $filterMessage = $compatibility['reason'] ?? 'Date filter unavailable for this widget.';
                    }
                }
            } catch (Throwable $e) {
                $filterApplied = false;
                $filterStatus = 'unsupported';
                $filterMessage = 'Invalid date filter: ' . $e->getMessage();
            }
        }

        // 3. Guardrail check (SELECT-only and forbidden keyword audit)
        $guardrailResult = $this->guardrailService->validate($sql);
        if (!$guardrailResult['allowed']) {
            QueryLog::create([
                'user_id' => $userId,
                'company_id' => $companyId,
                'database_connection_id' => $savedQuery->database_connection_id,
                'source' => $source,
                'question' => $savedQuery->natural_language_question,
                'generated_sql' => $sql,
                'passed_guardrails' => false,
                'execution_status' => 'blocked',
                'error_message' => $guardrailResult['reason'],
                'confidence_score' => 1.0,
            ]);

            return [
                'success' => false,
                'question' => $savedQuery->natural_language_question,
                'sql' => $sql,
                'guardrails' => $guardrailResult,
                'schema_validation' => null,
                'semantic_validation' => null,
                'execution' => [
                    'success' => false,
                    'error' => 'Blocked by guardrails: ' . $guardrailResult['reason'],
                    'time_ms' => 0,
                    'results' => []
                ],
                'confidence' => 1.0,
                'explanation' => 'Execution blocked by guardrails: ' . $guardrailResult['reason'],
                'visualization_type' => $savedQuery->result_visualization_type,
                'status_code' => 200,
            ];
        }

        // 4. Schema validation against active database schema (detects schema drift / dropped columns)
        $schemaResult = $this->schemaValidator->validate($sql, $customerSchema, $driver);
        if (!$schemaResult['valid']) {
            QueryLog::create([
                'user_id' => $userId,
                'company_id' => $companyId,
                'database_connection_id' => $savedQuery->database_connection_id,
                'source' => $source,
                'question' => $savedQuery->natural_language_question,
                'generated_sql' => $sql,
                'passed_guardrails' => true,
                'execution_status' => 'failed',
                'error_message' => 'Schema validation failed: ' . $schemaResult['reason'],
                'confidence_score' => 1.0,
            ]);

            return [
                'success' => false,
                'question' => $savedQuery->natural_language_question,
                'sql' => $sql,
                'guardrails' => [
                    'allowed' => true,
                    'reason' => null
                ],
                'schema_validation' => $schemaResult,
                'semantic_validation' => null,
                'execution' => [
                    'success' => false,
                    'error' => 'Schema validation failed: ' . $schemaResult['reason'],
                    'time_ms' => 0,
                    'results' => []
                ],
                'confidence' => 1.0,
                'explanation' => 'Schema validation failed: ' . $schemaResult['reason'] . '. The database schema may have changed since this query was saved.',
                'visualization_type' => $savedQuery->result_visualization_type,
                'status_code' => 200,
            ];
        }

        // 5. Safe Read-Only Execution with Parameterized Bindings and Guaranteed Connection Cleanup
        try {
            if ($databaseConnection !== null) {
                $this->connectionManager->getConnection($databaseConnection);
            }

            $executionResult = $this->executorService->execute($sql, $runtimeConnectionName, $bindings);
        } finally {
            if ($databaseConnection !== null) {
                $this->connectionManager->purgeConnection($databaseConnection);
            }
        }

        // 6. Deep Query Interpretation & Correctness Preservation (Grain, Joins, Fan-out Multiplication Risk)
        $activeSchemaDetails = $normalizedSchema ?: $this->defaultSchemaService->getSchemaDetails();
        $interpretation = $this->interpretationService->interpret($sql, $activeSchemaDetails);

        // 7. Tenant-safe Audit Logging with specified source
        QueryLog::create([
            'user_id' => $userId,
            'company_id' => $companyId,
            'database_connection_id' => $savedQuery->database_connection_id,
            'source' => $source,
            'question' => $savedQuery->natural_language_question,
            'generated_sql' => $sql,
            'passed_guardrails' => true,
            'execution_status' => $executionResult['success'] ? 'success' : 'failed',
            'execution_time_ms' => $executionResult['time_ms'],
            'error_message' => $executionResult['error'],
            'confidence_score' => 1.0,
        ]);

        $payload = [
            'success' => $executionResult['success'],
            'question' => $savedQuery->natural_language_question,
            'sql' => $sql,
            'bindings' => $bindings,
            'guardrails' => [
                'allowed' => true,
                'reason' => null
            ],
            'schema_validation' => [
                'valid' => true,
                'reason' => null
            ],
            'semantic_validation' => null,
            'interpretation' => $interpretation,
            'execution' => [
                'success' => $executionResult['success'],
                'error' => $executionResult['error'],
                'time_ms' => $executionResult['time_ms'],
                'results' => $executionResult['results']
            ],
            'confidence' => 1.0,
            'explanation' => "Re-executed saved query: {$savedQuery->name}",
            'visualization_type' => $savedQuery->result_visualization_type,
            'saved_query_id' => $savedQuery->id,
            'saved_query_name' => $savedQuery->name,
            'target_database_name' => $savedQuery->target_database_name,
            'filter_applied' => $filterApplied,
            'filter_status' => $filterStatus,
            'filter_message' => $filterMessage,
            'cache_hit' => false,
            'status_code' => 200,
        ];

        // Store successful execution in cache
        if ($source === 'dashboard' && $executionResult['success']) {
            $this->cacheService->put($cacheKey, $payload, DashboardCacheService::DEFAULT_TTL_SECONDS);
        }

        return $payload;
    }
}
