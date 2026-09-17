<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\DatabaseConnection;
use App\Models\QueryLog;
use App\Services\AiSqlManager;
use App\Services\DatabaseConnectionManager;
use App\Services\DatabaseSchemaService;
use App\Services\QuestionAmbiguityService;
use App\Services\SchemaIntrospectionService;
use App\Services\SchemaRelevanceService;
use App\Services\SqlExecutorService;
use App\Services\SqlGuardrailService;
use App\Services\SqlSchemaValidator;
use App\Services\SqlSemanticValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QueryController extends Controller
{
    protected AiSqlManager $aiManager;
    protected SqlGuardrailService $guardrailService;
    protected SqlSchemaValidator $schemaValidator;
    protected SqlSemanticValidator $semanticValidator;
    protected SqlExecutorService $executorService;
    protected DatabaseConnectionManager $connectionManager;
    protected SchemaIntrospectionService $introspectionService;
    protected SchemaRelevanceService $relevanceService;
    protected QuestionAmbiguityService $ambiguityService;
    protected DatabaseSchemaService $defaultSchemaService;

    public function __construct(
        AiSqlManager $aiManager,
        SqlGuardrailService $guardrailService,
        SqlSchemaValidator $schemaValidator,
        SqlSemanticValidator $semanticValidator,
        SqlExecutorService $executorService,
        DatabaseConnectionManager $connectionManager,
        SchemaIntrospectionService $introspectionService,
        SchemaRelevanceService $relevanceService,
        QuestionAmbiguityService $ambiguityService,
        DatabaseSchemaService $defaultSchemaService
    ) {
        $this->aiManager = $aiManager;
        $this->guardrailService = $guardrailService;
        $this->schemaValidator = $schemaValidator;
        $this->semanticValidator = $semanticValidator;
        $this->executorService = $executorService;
        $this->connectionManager = $connectionManager;
        $this->introspectionService = $introspectionService;
        $this->relevanceService = $relevanceService;
        $this->ambiguityService = $ambiguityService;
        $this->defaultSchemaService = $defaultSchemaService;
    }

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'question' => 'required_without:sql|nullable|string|max:1000',
            'sql' => 'nullable|string|max:2000',
            'database_connection_id' => 'nullable|integer',
            'source' => 'nullable|string|in:natural_language,custom_sql,saved_query',
        ]);

        $customSql = $request->input('sql');
        $isCustomSql = !empty($customSql);
        $question = $request->input('question') ?? ($isCustomSql ? 'Custom SQL Query' : '');
        $connId = $request->input('database_connection_id');
        $source = $request->input('source') ?? ($isCustomSql ? 'custom_sql' : 'natural_language');

        $user = $request->user('sanctum');
        $userId = $user?->id;
        $companyId = $user?->company_id;
        $requestId = $request->attributes->get('request_id');

        // Viewers cannot execute arbitrary queries in the workspace
        if ($user && $user->isViewer()) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        // 0. Ambiguity Detection (Bypassed for custom SQL)
        if (!$isCustomSql) {
            $ambiguity = $this->ambiguityService->evaluateAmbiguity($question, $companyId);
            if ($ambiguity['ambiguous']) {
                return response()->json([
                    'question' => $question,
                    'sql' => null,
                    'ambiguous' => true,
                    'clarification' => $ambiguity['clarification'],
                    'suggestions' => $ambiguity['suggestions'],
                    'guardrails' => [
                        'allowed' => false,
                        'reason' => 'Ambiguous question requires clarification.'
                    ],
                    'schema_validation' => null,
                    'semantic_validation' => null,
                    'execution' => [
                        'success' => false,
                        'error' => null,
                        'time_ms' => 0,
                        'results' => []
                    ],
                    'confidence' => null,
                    'explanation' => $ambiguity['clarification'],
                    'relevant_schema' => null
                ]);
            }
        }

        $databaseConnection = null;
        $customerSchema = null;
        $schemaContext = null;
        $runtimeConnectionName = null;
        $driver = 'mysql';
        $relevantInfo = null;

        // 1. Resolve target database connection and normalized schema
        if ($connId !== null) {
            if (!$user || !$user->company_id) {
                return response()->json([
                    'message' => 'Unauthenticated. Please log in to query a customer database.'
                ], 401);
            }

            $databaseConnection = DatabaseConnection::where('company_id', $user->company_id)
                ->where('id', $connId)
                ->first();

            if (!$databaseConnection) {
                return response()->json([
                    'message' => 'Database connection not found or access denied.'
                ], 404);
            }

            $driver = strtolower($databaseConnection->driver);
            $runtimeConnectionName = $this->connectionManager->getConnectionName($databaseConnection);

            try {
                $normalizedSchema = $this->introspectionService->introspect($databaseConnection);
                $customerSchema = $this->introspectionService->getTablesAndColumns($normalizedSchema);

                // Run schema relevance selection
                $relevanceResult = $this->relevanceService->selectRelevantSchema($question, $normalizedSchema, $companyId);
                $relevantSchema = $relevanceResult['schema'];
                $schemaContext = $this->introspectionService->getPromptContext($relevantSchema);

                $relevantInfo = [
                    'is_subset' => $relevanceResult['is_subset'],
                    'matched_tables' => $relevanceResult['matched_tables'],
                    'bridge_tables' => $relevanceResult['bridge_tables'],
                    'total_tables' => count($normalizedSchema['tables'] ?? []),
                    'included_tables' => count($relevantSchema['tables'] ?? []),
                ];
            } catch (\Throwable $e) {
                return response()->json([
                    'question' => $question,
                    'sql' => null,
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
                    'confidence' => null,
                    'explanation' => null,
                    'relevant_schema' => null
                ], 422);
            }
        } else {
            // Demo database mode
            $normalizedSchema = $this->defaultSchemaService->getSchemaDetails();
            $customerSchema = $this->defaultSchemaService->getTablesAndColumns();

            $relevanceResult = $this->relevanceService->selectRelevantSchema($question, $normalizedSchema, $companyId);
            $relevantSchema = $relevanceResult['schema'];
            $schemaContext = $this->introspectionService->getPromptContext($relevantSchema);

            $relevantInfo = [
                'is_subset' => $relevanceResult['is_subset'],
                'matched_tables' => $relevanceResult['matched_tables'],
                'bridge_tables' => $relevanceResult['bridge_tables'],
                'total_tables' => count($normalizedSchema['tables'] ?? []),
                'included_tables' => count($relevantSchema['tables'] ?? []),
            ];
        }

        // Inject company business semantic layer intelligence into AI prompt context
        if ($companyId) {
            $semanticPrompt = app(\App\Services\SemanticContextService::class)->getSemanticPromptContext($question, $companyId, $relevantSchema);
            if (!empty($semanticPrompt)) {
                $schemaContext .= "\n" . $semanticPrompt;
            }
        }

        // 2. Generate SQL or use custom user SQL
        if ($isCustomSql) {
            $generatedSql = $customSql;
            $confidence = null;
            $explanation = 'Executed custom user-edited SQL query.';
        } else {
            $aiResponse = $this->aiManager->generateSql($question, $schemaContext, $driver);

            if (!$aiResponse->success) {
                QueryLog::create([
                    'request_id' => $requestId,
                    'user_id' => $userId,
                    'company_id' => $companyId,
                    'database_connection_id' => $connId,
                    'source' => $source,
                    'question' => $question,
                    'generated_sql' => null,
                    'passed_guardrails' => false,
                    'execution_status' => 'failed',
                    'error_message' => $aiResponse->error,
                    'error_code' => 'AI_TRANSLATION_FAILED',
                    'confidence_score' => null
                ]);

                return response()->json([
                    'question' => $question,
                    'sql' => null,
                    'guardrails' => [
                        'allowed' => false,
                        'reason' => 'AI translation failed: ' . $aiResponse->error
                    ],
                    'schema_validation' => null,
                    'semantic_validation' => null,
                    'execution' => [
                        'success' => false,
                        'error' => $aiResponse->error,
                        'error_code' => 'AI_TRANSLATION_FAILED',
                        'time_ms' => 0,
                        'results' => []
                    ],
                    'confidence' => null,
                    'explanation' => null,
                    'relevant_schema' => $relevantInfo,
                    'request_id' => $requestId,
                ]);
            }

            $generatedSql = $aiResponse->sql;
            $confidence = $aiResponse->confidence;
            $explanation = $aiResponse->explanation;
        }

        // 3. Validate using Guardrail Service (Read-only enforcement)
        $guardrailResult = $this->guardrailService->validate($generatedSql);

        if (!$guardrailResult['allowed']) {
            QueryLog::create([
                'request_id' => $requestId,
                'user_id' => $userId,
                'company_id' => $companyId,
                'database_connection_id' => $connId,
                'source' => $source,
                'question' => $question,
                'generated_sql' => $generatedSql,
                'passed_guardrails' => false,
                'execution_status' => 'blocked',
                'error_message' => $guardrailResult['reason'],
                'error_code' => 'GUARDRAIL_BLOCKED',
                'confidence_score' => $confidence
            ]);

            return response()->json([
                'question' => $question,
                'sql' => $generatedSql,
                'guardrails' => [
                    'allowed' => false,
                    'reason' => $guardrailResult['reason']
                ],
                'schema_validation' => null,
                'semantic_validation' => null,
                'execution' => [
                    'success' => false,
                    'error' => 'Blocked by guardrails: ' . $guardrailResult['reason'],
                    'error_code' => 'GUARDRAIL_BLOCKED',
                    'time_ms' => 0,
                    'results' => []
                ],
                'confidence' => $confidence,
                'explanation' => $explanation,
                'relevant_schema' => $relevantInfo,
                'request_id' => $requestId,
            ]);
        }

        // 4. Dialect-aware Schema Validation with Controlled 1-Attempt Retry
        $schemaResult = $this->schemaValidator->validate($generatedSql, $customerSchema, $driver);

        if (!$schemaResult['valid'] && !$isCustomSql && config('schema.retry_on_schema_failure', true)) {
            // Attempt MAX 1 controlled regeneration retry using the validation failure reason
            $retryResponse = $this->aiManager->generateSqlWithCorrection(
                $question,
                $schemaContext,
                $driver,
                $generatedSql,
                $schemaResult['reason'] ?? 'Schema validation failed.'
            );

            if ($retryResponse->success && !empty($retryResponse->sql)) {
                $retriedSql = $retryResponse->sql;
                $retriedGuardrail = $this->guardrailService->validate($retriedSql);

                if ($retriedGuardrail['allowed']) {
                    $retriedSchema = $this->schemaValidator->validate($retriedSql, $customerSchema, $driver);
                    if ($retriedSchema['valid']) {
                        $generatedSql = $retriedSql;
                        $confidence = $retryResponse->confidence;
                        $explanation = $retryResponse->explanation . ' (Corrected on schema feedback retry)';
                        $schemaResult = $retriedSchema;
                    }
                }
            }
        }

        if (!$schemaResult['valid']) {
            QueryLog::create([
                'request_id' => $requestId,
                'user_id' => $userId,
                'company_id' => $companyId,
                'database_connection_id' => $connId,
                'source' => $source,
                'question' => $question,
                'generated_sql' => $generatedSql,
                'passed_guardrails' => true,
                'execution_status' => 'failed',
                'error_message' => 'Schema validation failed: ' . $schemaResult['reason'],
                'error_code' => 'SCHEMA_VALIDATION_FAILED',
                'confidence_score' => $confidence
            ]);

            return response()->json([
                'question' => $question,
                'sql' => $generatedSql,
                'guardrails' => [
                    'allowed' => true,
                    'reason' => null
                ],
                'schema_validation' => [
                    'valid' => false,
                    'reason' => $schemaResult['reason']
                ],
                'semantic_validation' => null,
                'execution' => [
                    'success' => false,
                    'error' => 'Schema validation failed: ' . $schemaResult['reason'],
                    'error_code' => 'SCHEMA_VALIDATION_FAILED',
                    'time_ms' => 0,
                    'results' => []
                ],
                'confidence' => $confidence,
                'explanation' => $explanation,
                'relevant_schema' => $relevantInfo,
                'request_id' => $requestId,
            ]);
        }

        // 5. Semantic Validation & Query Interpretation
        $semanticResult = null;
        if (!$isCustomSql) {
            $semanticResult = $this->semanticValidator->validate($question, $generatedSql, $schemaContext, $customerSchema, $driver, $normalizedSchema, $companyId);

            if (!$semanticResult['valid']) {
                QueryLog::create([
                    'request_id' => $requestId,
                    'user_id' => $userId,
                    'company_id' => $companyId,
                    'database_connection_id' => $connId,
                    'source' => $source,
                    'question' => $question,
                    'generated_sql' => $generatedSql,
                    'passed_guardrails' => true,
                    'execution_status' => 'blocked',
                    'error_message' => 'Semantic validation failed: ' . $semanticResult['reason'],
                    'error_code' => 'SEMANTIC_VALIDATION_BLOCKED',
                    'risk_level' => $semanticResult['risk_level'] ?? 'low',
                    'confidence_score' => $confidence
                ]);

                return response()->json([
                    'question' => $question,
                    'sql' => $generatedSql,
                    'guardrails' => [
                        'allowed' => true,
                        'reason' => null
                    ],
                    'schema_validation' => [
                        'valid' => true,
                        'reason' => null
                    ],
                    'semantic_validation' => $semanticResult,
                    'execution' => [
                        'success' => false,
                        'error' => 'Blocked by semantic intent verification: ' . $semanticResult['reason'],
                        'error_code' => 'SEMANTIC_VALIDATION_BLOCKED',
                        'time_ms' => 0,
                        'results' => []
                    ],
                    'confidence' => $confidence,
                    'explanation' => $explanation,
                    'relevant_schema' => $relevantInfo,
                    'request_id' => $requestId,
                ]);
            }
        } else {
            // For custom SQL, extract query interpretation, joins, grain, and potential multiplication risk + business semantics
            $interpretation = app(\App\Services\QueryInterpretationService::class)->interpret($generatedSql, $normalizedSchema);
            $structure = $this->semanticValidator->extractStructure($generatedSql);
            $baseCustomDetails = [
                'tables' => $interpretation['tables'],
                'operations' => $structure['operations'],
                'filters' => $interpretation['filters'],
                'grouping' => $structure['grouping'],
                'ordering' => $structure['ordering'],
                'joins' => $interpretation['joins'],
                'grain' => $interpretation['grain'],
                'aggregations' => $interpretation['aggregations'],
                'multiplication_risk' => $interpretation['multiplication_risk'],
                'risk_level' => $interpretation['risk_level'] ?? 'low',
                'risk_reasons' => $interpretation['risk_reasons'] ?? [],
            ];
            $semantics = $this->semanticValidator->evaluateBusinessSemantics($question, $generatedSql, $baseCustomDetails, $companyId);

            $semanticResult = array_merge([
                'valid' => true,
                'score' => 1.0,
                'reason' => null,
                'interpretation' => 'Custom SQL query targeting ' . implode(', ', $interpretation['tables']),
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
            ], $baseCustomDetails);
        }

        // 6. Safe Read-Only Execution with Guaranteed Connection Cleanup
        try {
            if ($databaseConnection !== null) {
                $this->connectionManager->getConnection($databaseConnection);
            }

            $executionResult = $this->executorService->execute($generatedSql, $runtimeConnectionName);
        } finally {
            if ($databaseConnection !== null) {
                $this->connectionManager->purgeConnection($databaseConnection);
            }
        }

        $riskLevel = $semanticResult['risk_level'] ?? 'low';
        $riskReasons = $semanticResult['risk_reasons'] ?? [];
        $rowsReturned = $executionResult['returned_rows'] ?? (is_array($executionResult['results'] ?? null) ? count($executionResult['results']) : 0);
        $truncated = $executionResult['truncated'] ?? false;
        $errorCode = $executionResult['error_code'] ?? null;

        // 7. Tenant-safe Audit Logging
        QueryLog::create([
            'request_id' => $requestId,
            'user_id' => $userId,
            'company_id' => $companyId,
            'database_connection_id' => $connId,
            'source' => $source,
            'question' => $question,
            'generated_sql' => $generatedSql,
            'passed_guardrails' => true,
            'execution_status' => $executionResult['success'] ? 'success' : 'failed',
            'execution_time_ms' => $executionResult['time_ms'],
            'rows_returned' => $rowsReturned,
            'truncated' => $truncated,
            'risk_level' => $riskLevel,
            'error_message' => $executionResult['error'],
            'error_code' => $errorCode,
            'confidence_score' => $confidence
        ]);

        return response()->json([
            'question' => $question,
            'sql' => $generatedSql,
            'guardrails' => [
                'allowed' => true,
                'reason' => null
            ],
            'schema_validation' => [
                'valid' => true,
                'reason' => null
            ],
            'semantic_validation' => $semanticResult,
            'execution' => [
                'success' => $executionResult['success'],
                'error' => $executionResult['error'],
                'error_code' => $errorCode,
                'time_ms' => $executionResult['time_ms'],
                'results' => $executionResult['results'],
                'truncated' => $truncated,
                'returned_rows' => $rowsReturned,
                'limit' => $executionResult['limit'] ?? config('reliability.query_max_rows', 1000),
                'total_rows' => $executionResult['total_rows'] ?? $rowsReturned,
            ],
            'risk_level' => $riskLevel,
            'risk_reasons' => $riskReasons,
            'confidence' => $confidence,
            'explanation' => $explanation,
            'relevant_schema' => $relevantInfo,
            'request_id' => $requestId,
        ]);
    }
}
