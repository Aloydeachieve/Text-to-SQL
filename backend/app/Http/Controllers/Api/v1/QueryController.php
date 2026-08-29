<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Services\AiSqlManager;
use App\Services\SqlGuardrailService;
use App\Services\SqlSchemaValidator;
use App\Services\SqlExecutorService;
use App\Models\QueryLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class QueryController extends Controller
{
    protected AiSqlManager $aiManager;
    protected SqlGuardrailService $guardrailService;
    protected SqlSchemaValidator $schemaValidator;
    protected SqlExecutorService $executorService;

    public function __construct(
        AiSqlManager $aiManager,
        SqlGuardrailService $guardrailService,
        SqlSchemaValidator $schemaValidator,
        SqlExecutorService $executorService
    ) {
        $this->aiManager = $aiManager;
        $this->guardrailService = $guardrailService;
        $this->schemaValidator = $schemaValidator;
        $this->executorService = $executorService;
    }

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'question' => 'required|string|max:1000',
            'sql' => 'nullable|string|max:2000'
        ]);

        $question = $request->input('question');
        $customSql = $request->input('sql');

        if (!empty($customSql)) {
            // Manual Custom SQL execution path
            $generatedSql = $customSql;
            $confidence = null;
            $explanation = 'Executed custom user-edited SQL query.';
        } else {
            // 1. Generate SQL via active AI driver
            $aiResponse = $this->aiManager->generateSql($question);

            if (!$aiResponse->success) {
                // Log failed generation in query history
                QueryLog::create([
                    'question' => $question,
                    'generated_sql' => null,
                    'passed_guardrails' => false,
                    'execution_status' => 'failed',
                    'error_message' => $aiResponse->error,
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
                    'execution' => [
                        'success' => false,
                        'error' => $aiResponse->error,
                        'time_ms' => 0,
                        'results' => []
                    ],
                    'confidence' => null,
                    'explanation' => null
                ]);
            }

            $generatedSql = $aiResponse->sql;
            $confidence = $aiResponse->confidence;
            $explanation = $aiResponse->explanation;
        }

        // 2. Validate using Guardrail Service
        $guardrailResult = $this->guardrailService->validate($generatedSql);

        if (!$guardrailResult['allowed']) {
            // Log blocked query in query history
            QueryLog::create([
                'question' => $question,
                'generated_sql' => $generatedSql,
                'passed_guardrails' => false,
                'execution_status' => 'blocked',
                'error_message' => $guardrailResult['reason'],
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
                'execution' => [
                    'success' => false,
                    'error' => 'Blocked by guardrails: ' . $guardrailResult['reason'],
                    'time_ms' => 0,
                    'results' => []
                ],
                'confidence' => $confidence,
                'explanation' => $explanation
            ]);
        }

        // 3. Validate against DB schema rules (Database-aware validation)
        $schemaResult = $this->schemaValidator->validate($generatedSql);

        if (!$schemaResult['valid']) {
            // Log schema validation block in history as a failed query
            QueryLog::create([
                'question' => $question,
                'generated_sql' => $generatedSql,
                'passed_guardrails' => true,
                'execution_status' => 'failed',
                'error_message' => 'Schema validation failed: ' . $schemaResult['reason'],
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
                'execution' => [
                    'success' => false,
                    'error' => 'Schema validation failed: ' . $schemaResult['reason'],
                    'time_ms' => 0,
                    'results' => []
                ],
                'confidence' => $confidence,
                'explanation' => $explanation
            ]);
        }

        // 4. Execute query safely
        $executionResult = $this->executorService->execute($generatedSql);

        // 5. Log full run in history
        QueryLog::create([
            'question' => $question,
            'generated_sql' => $generatedSql,
            'passed_guardrails' => true,
            'execution_status' => $executionResult['success'] ? 'success' : 'failed',
            'execution_time_ms' => $executionResult['time_ms'],
            'error_message' => $executionResult['error'],
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
            'execution' => [
                'success' => $executionResult['success'],
                'error' => $executionResult['error'],
                'time_ms' => $executionResult['time_ms'],
                'results' => $executionResult['results']
            ],
            'confidence' => $confidence,
            'explanation' => $explanation
        ]);
    }
}
