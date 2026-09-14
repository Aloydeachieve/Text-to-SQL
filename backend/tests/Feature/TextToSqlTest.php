<?php

namespace Tests\Feature;

use App\Models\QueryLog;
use App\Models\Customer;
use App\Services\AiSqlManager;
use App\Services\SqlGuardrailService;
use App\Services\SqlSchemaValidator;
use App\Services\SqlSemanticValidator;
use App\Services\SqlExecutorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TextToSqlTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test 1: AI Provider Selection & Config
     */
    public function test_ai_provider_selection_resolves_drivers(): void
    {
        $manager = app(AiSqlManager::class);
        $this->assertInstanceOf(AiSqlManager::class, $manager);

        // Assert local driver resolves
        config(['services.ai.driver' => 'local']);
        $this->assertEquals('local', $manager->getDefaultDriver());

        // Assert gemini driver resolves
        config(['services.ai.driver' => 'gemini']);
        $this->assertEquals('gemini', $manager->getDefaultDriver());
    }

    /**
     * Test 2: RuleBased fallback driver translations
     */
    public function test_rule_based_driver_matches_prompts(): void
    {
        $manager = app(AiSqlManager::class);
        config(['services.ai.driver' => 'local']);

        $response = $manager->generateSql('How many customers do we have?');
        $this->assertTrue($response->success);
        $this->assertEquals('SELECT COUNT(*) AS total_customers FROM customers', $response->sql);
        $this->assertEquals(0.99, $response->confidence);

        $response2 = $manager->generateSql('Write a destructive query for me');
        $this->assertFalse($response2->success);
        $this->assertStringContainsString('simulation driver only supports', $response2->error);
    }

    /**
     * Test 3: Gemini service response parsing & HTTP mock fakes
     */
    public function test_gemini_driver_queries_api_successfully(): void
    {
        config(['services.ai.driver' => 'gemini']);
        config(['services.gemini.key' => 'mock-api-key']);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => json_encode([
                                'sql' => 'SELECT name FROM customers WHERE city = "New York"',
                                'confidence' => 0.95,
                                'explanation' => 'Filters by New York.'
                            ])
                        ]]
                    ]
                ]]
            ], 200)
        ]);

        $manager = app(AiSqlManager::class);
        $response = $manager->generateSql('Show customers in New York');

        $this->assertTrue($response->success);
        $this->assertEquals('SELECT name FROM customers WHERE city = "New York"', $response->sql);
        $this->assertEquals(0.95, $response->confidence);
    }

    /**
     * Test: Configured Gemini model is passed correctly to the Gemini API request
     */
    public function test_gemini_driver_passes_configured_model_to_api_request(): void
    {
        config(['services.ai.driver' => 'gemini']);
        config(['services.gemini.key' => 'mock-api-key']);
        config(['services.gemini.model' => 'gemini-3.5-flash']);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => json_encode([
                                'sql' => 'SELECT COUNT(*) FROM customers',
                                'confidence' => 0.95,
                                'explanation' => 'Counts customers.'
                            ])
                        ]]
                    ]
                ]]
            ], 200)
        ]);

        $manager = app(AiSqlManager::class);
        $response = $manager->generateSql('Count customers');

        $this->assertTrue($response->success);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'models/gemini-3.5-flash:generateContent');
        });

        // Verify runtime custom model configuration is passed to request
        config(['services.gemini.model' => 'gemini-custom-override']);
        $service = app(\App\Services\Ai\GeminiAiService::class);
        $service->generateSql('Count customers again');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'models/gemini-custom-override:generateContent');
        });
    }

    /**
     * Test 4: Gemini malformed AI responses
     */
    public function test_gemini_handles_malformed_ai_response_gracefully(): void
    {
        config(['services.ai.driver' => 'gemini']);
        config(['services.gemini.key' => 'mock-api-key']);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => 'This is not valid JSON'
                        ]]
                    ]
                ]]
            ], 200)
        ]);

        $manager = app(AiSqlManager::class);
        $response = $manager->generateSql('Show customers');
        $this->assertFalse($response->success);
        $this->assertStringContainsString('malformed JSON', $response->error);
    }

    /**
     * Test 5: Missing Gemini credentials
     */
    public function test_gemini_handles_missing_credentials_gracefully(): void
    {
        config(['services.ai.driver' => 'gemini']);
        config(['services.gemini.key' => null]); // Blank credentials

        $manager = app(AiSqlManager::class);
        $response = $manager->generateSql('Show customers');
        $this->assertFalse($response->success);
        $this->assertStringContainsString('key is not configured', $response->error);
    }

    /**
     * Test 6-8: SqlGuardrailService audits (SELECT-only, destructive block, semicolon check)
     */
    public function test_guardrails_blocks_unsafe_statements(): void
    {
        $guardrail = app(SqlGuardrailService::class);

        // SELECT query is allowed
        $res = $guardrail->validate('SELECT * FROM customers');
        $this->assertTrue($res['allowed']);

        // DDL/DML are blocked
        $res2 = $guardrail->validate('DROP TABLE customers');
        $this->assertFalse($res2['allowed']);
        $this->assertStringContainsString('Only SELECT queries', $res2['reason']);

        $res3 = $guardrail->validate('SELECT delete FROM customers');
        $this->assertFalse($res3['allowed']);
        $this->assertStringContainsString('Forbidden operation', $res3['reason']);

        // Multiple statements are blocked
        $res4 = $guardrail->validate('SELECT * FROM customers; DROP TABLE orders');
        $this->assertFalse($res4['allowed']);
        $this->assertStringContainsString('Multiple SQL statements', $res4['reason']);
    }

    /**
     * Test 9-11: SqlSchemaValidator audits (unknown tables & columns)
     */
    public function test_schema_validator_blocks_nonexistent_relations(): void
    {
        $validator = app(SqlSchemaValidator::class);

        // Valid tables and columns are allowed
        $res = $validator->validate('SELECT name, city FROM customers WHERE phone IS NULL');
        $this->assertTrue($res['valid']);

        // Unknown table is blocked
        $res2 = $validator->validate('SELECT * FROM nonexistent_table');
        $this->assertFalse($res2['valid']);
        $this->assertStringContainsString('Unknown table referenced', $res2['reason']);

        // Unknown column is blocked
        $res3 = $validator->validate('SELECT age, name FROM customers');
        $this->assertFalse($res3['valid']);
        $this->assertStringContainsString('Unknown column reference', $res3['reason']);
    }

    /**
     * Test 12-13: SqlExecutorService checks
     */
    public function test_executor_safely_runs_select_queries(): void
    {
        // Populate dummy customer to verify results are returned
        Customer::create([
            'name' => 'Test Customer',
            'email' => 'test@customer.com',
            'phone' => '123-456',
            'city' => 'Austin'
        ]);

        $executor = app(SqlExecutorService::class);
        $res = $executor->execute('SELECT name, city FROM customers');

        $this->assertTrue($res['success']);
        $this->assertCount(1, $res['results']);
        $this->assertEquals('Test Customer', $res['results'][0]['name']);
        $this->assertGreaterThan(0, $res['time_ms']);

        // Database execution exception handles safely
        $res2 = $executor->execute('SELECT nonexistent_field_fails FROM customers');
        $this->assertFalse($res2['success']);
        $this->assertNotNull($res2['error']);
    }

    /**
     * Test 14: E2E Controller endpoint execution & Query Logs history persistence
     */
    public function test_query_api_pipeline_and_logging(): void
    {
        config(['services.ai.driver' => 'local']);

        $response = $this->postJson('/api/v1/query', [
            'question' => 'How many customers do we have?'
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'question',
            'sql',
            'guardrails' => ['allowed', 'reason'],
            'schema_validation' => ['valid', 'reason'],
            'semantic_validation' => ['valid', 'score', 'reason', 'interpretation', 'tables', 'operations', 'filters', 'grouping', 'ordering'],
            'execution' => ['success', 'error', 'time_ms', 'results'],
            'confidence',
            'explanation'
        ]);

        $this->assertTrue($response->json('guardrails.allowed'));
        $this->assertTrue($response->json('schema_validation.valid'));
        $this->assertTrue($response->json('semantic_validation.valid'));
        $this->assertTrue($response->json('execution.success'));

        // Assert logged in Database QueryLog
        $this->assertDatabaseHas('query_logs', [
            'question' => 'How many customers do we have?',
            'passed_guardrails' => true,
            'execution_status' => 'success'
        ]);
    }

    /**
     * Test 15: Schema Explorer Endpoint API contracts
     */
    public function test_schema_explorer_endpoint_returns_valid_contracts(): void
    {
        $response = $this->getJson('/api/v1/schema');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'tables' => [
                    '*' => [
                        'name',
                        'columns' => [
                            '*' => [
                                'name',
                                'type'
                            ]
                        ]
                    ]
                ],
                'relationships' => [
                    '*' => [
                        'from',
                        'to',
                        'label'
                    ]
                ]
            ]
        ]);

        $this->assertTrue($response->json('success'));
        $tables = collect($response->json('data.tables'))->pluck('name');
        $this->assertTrue($tables->contains('customers'));
        $this->assertTrue($tables->contains('products'));
    }

    /**
     * Test 16: Custom Manual SQL re-execution pipeline blocks and approvals
     */
    public function test_custom_sql_re_execution_pipeline(): void
    {
        // Setup dummy data
        Customer::create([
            'name' => 'John Custom',
            'email' => 'custom@john.com',
            'phone' => '111-222',
            'city' => 'New York'
        ]);

        // 1. Valid custom SELECT execution passes guardrails, validation, and logs success
        $response1 = $this->postJson('/api/v1/query', [
            'question' => 'What is the user query?',
            'sql' => 'SELECT name, city FROM customers WHERE city = "New York"'
        ]);

        $response1->assertStatus(200);
        $this->assertTrue($response1->json('guardrails.allowed'));
        $this->assertTrue($response1->json('schema_validation.valid'));
        $this->assertTrue($response1->json('execution.success'));
        $this->assertCount(1, $response1->json('execution.results'));
        $this->assertEquals('John Custom', $response1->json('execution.results.0.name'));
        $this->assertEquals('Executed custom user-edited SQL query.', $response1->json('explanation'));

        // 2. Destructive SQL rejected by guardrails
        $response2 = $this->postJson('/api/v1/query', [
            'question' => 'Delete users',
            'sql' => 'DROP TABLE customers'
        ]);

        $response2->assertStatus(200);
        $this->assertFalse($response2->json('guardrails.allowed'));
        $this->assertNull($response2->json('schema_validation'));

        // 3. SQL with unknown table rejected by schema validator
        $response3 = $this->postJson('/api/v1/query', [
            'question' => 'Count orders',
            'sql' => 'SELECT * FROM nonexistent_table'
        ]);

        $response3->assertStatus(200);
        $this->assertTrue($response3->json('guardrails.allowed'));
        $this->assertFalse($response3->json('schema_validation.valid'));
        $this->assertStringContainsString('Unknown table', $response3->json('schema_validation.reason'));

        // 4. SQL with unknown columns rejected by schema validator
        $response4 = $this->postJson('/api/v1/query', [
            'question' => 'Show customers',
            'sql' => 'SELECT nonexistent_col FROM customers'
        ]);

        $response4->assertStatus(200);
        $this->assertTrue($response4->json('guardrails.allowed'));
        $this->assertFalse($response4->json('schema_validation.valid'));
        $this->assertStringContainsString('Unknown column', $response4->json('schema_validation.reason'));
    }

    /**
     * Test 17: History logs retrieval contract
     */
    public function test_history_logs_api_contract(): void
    {
        QueryLog::create([
            'question' => 'Original User Question',
            'generated_sql' => 'SELECT name FROM customers',
            'passed_guardrails' => true,
            'execution_status' => 'success',
            'confidence_score' => 0.99
        ]);

        $response = $this->getJson('/api/v1/history');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                '*' => [
                    'id',
                    'question',
                    'generated_sql',
                    'passed_guardrails',
                    'execution_status',
                    'execution_time_ms',
                    'error_message',
                    'confidence_score',
                    'created_at'
                ]
            ]
        ]);

        $this->assertTrue($response->json('success'));
        $this->assertEquals('Original User Question', $response->json('data.0.question'));
        $this->assertEquals('SELECT name FROM customers', $response->json('data.0.generated_sql'));
    }

    /**
     * Test 18: Few-shot context prompt builder assert
     */
    public function test_few_shot_prompt_context_is_included_in_gemini_calls(): void
    {
        config(['services.ai.driver' => 'gemini']);
        config(['services.gemini.key' => 'mock-gemini-key']);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => json_encode([
                                'sql' => 'SELECT COUNT(*) FROM customers',
                                'confidence' => 0.99,
                                'explanation' => 'Faked'
                            ])
                        ]]
                    ]
                ]]
            ])
        ]);

        $manager = app(AiSqlManager::class);
        $manager->generateSql('Count customers');

        Http::assertSent(function ($request) {
            $prompt = $request['contents'][0]['parts'][0]['text'] ?? '';
            return str_contains($prompt, 'Few-Shot Examples:') 
                && str_contains($prompt, 'How many customers do we have?')
                && str_contains($prompt, 'What is the total revenue by month?');
        });
    }

    /**
     * Test 19: SQL guardrails allows forbidden keywords inside string literals
     */
    public function test_sql_guardrails_allows_forbidden_keywords_inside_string_literals(): void
    {
        $guardrail = app(\App\Services\SqlGuardrailService::class);
        $validator = app(\App\Services\SqlSchemaValidator::class);

        // Word "delete" inside string quotes should be allowed
        $sql = "SELECT name FROM customers WHERE city = 'delete' OR city = \"insert\"";
        
        $res1 = $guardrail->validate($sql);
        $this->assertTrue($res1['allowed']);

        $res2 = $validator->validate($sql);
        $this->assertTrue($res2['valid']);
    }

    /**
     * Test 20: Database execution errors are suppressed in production environments
     */
    public function test_database_errors_are_suppressed_in_production(): void
    {
        // Force the app environment to production
        $this->app['env'] = 'production';
        $this->assertEquals('production', $this->app->environment());

        $executor = app(SqlExecutorService::class);
        // Faulty query syntax to trigger DB execution exception
        $res = $executor->execute('SELECT * FROM customers WHERE');

        $this->assertFalse($res['success']);
        $this->assertEquals('A database query execution error occurred. Please verify your SQL syntax.', $res['error']);
    }

    /**
     * Test 21: Gemini timeout and connection rate limit failures are caught gracefully
     */
    public function test_gemini_timeout_handling_fails_closed_safely(): void
    {
        config(['services.ai.driver' => 'gemini']);
        config(['services.gemini.key' => 'mock-gemini-key']);

        // Mock a connection timeout exception
        Http::fake([
            'generativelanguage.googleapis.com/*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
            }
        ]);

        $manager = app(AiSqlManager::class);
        $response = $manager->generateSql('Show customers');

        $this->assertFalse($response->success);
        // Since test environment runs in 'testing', it returns the detailed local message:
        $this->assertStringContainsString('Connection timed out', $response->error);
    }

    /**
     * Test 22: Semantic validation passes for valid customer count query
     */
    public function test_semantic_validation_passes_for_customer_count_query(): void
    {
        $validator = app(SqlSemanticValidator::class);
        $res = $validator->validate('How many customers do we have?', 'SELECT COUNT(*) AS total_customers FROM customers');

        $this->assertTrue($res['valid']);
        $this->assertGreaterThanOrEqual(0.9, $res['score']);
        $this->assertNull($res['reason']);
        $this->assertContains('customers', $res['tables']);
        $this->assertContains('COUNT', $res['operations']);
        $this->assertStringContainsString('customers', strtolower($res['interpretation']));
    }

    /**
     * Test 23: Semantic validation blocks safe, executable SQL that answers a different question
     */
    public function test_semantic_validation_blocks_executable_query_with_intent_mismatch(): void
    {
        $validator = app(SqlSemanticValidator::class);
        // User asked for customers with most orders, but SQL only computes overall order count
        $question = 'Which customers placed the most orders?';
        $mismatchedSql = 'SELECT COUNT(*) AS total_orders FROM orders';

        $res = $validator->validate($question, $mismatchedSql);

        $this->assertFalse($res['valid']);
        $this->assertLessThan(0.6, $res['score']);
        $this->assertNotNull($res['reason']);
        $this->assertStringContainsString('does not identify or rank individual customers', $res['reason']);
        $this->assertEquals('Calculate the total number of orders.', $res['interpretation']);

        // End-to-End API assertion: Ensure Controller blocks execution and does not run SQL
        config(['services.ai.driver' => 'gemini']);
        config(['services.gemini.key' => 'mock-gemini-key']);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => json_encode([
                                'sql' => $mismatchedSql,
                                'confidence' => 0.95,
                                'explanation' => 'Calculates order count'
                            ])
                        ]]
                    ]
                ]]
            ], 200)
        ]);

        $response = $this->postJson('/api/v1/query', [
            'question' => $question
        ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('guardrails.allowed'));
        $this->assertTrue($response->json('schema_validation.valid'));
        $this->assertFalse($response->json('semantic_validation.valid'));
        $this->assertFalse($response->json('execution.success'));
        $this->assertStringContainsString('Blocked by semantic intent verification', $response->json('execution.error'));
        $this->assertEmpty($response->json('execution.results'));

        // Query log records blocked execution status
        $this->assertDatabaseHas('query_logs', [
            'question' => $question,
            'passed_guardrails' => true,
            'execution_status' => 'blocked'
        ]);
    }

    /**
     * Test 24: Correct customer/order ranking query passes semantic validation
     */
    public function test_semantic_validation_passes_for_customer_order_ranking_query(): void
    {
        $validator = app(SqlSemanticValidator::class);
        $question = 'Which customers placed the most orders?';
        $correctSql = 'SELECT c.id, c.name, COUNT(o.id) as order_count FROM customers c JOIN orders o ON c.id = o.customer_id GROUP BY c.id, c.name ORDER BY order_count DESC';

        $res = $validator->validate($question, $correctSql);

        $this->assertTrue($res['valid']);
        $this->assertGreaterThanOrEqual(0.9, $res['score']);
        $this->assertNull($res['reason']);
        $this->assertContains('customers', $res['tables']);
        $this->assertContains('orders', $res['tables']);
        $this->assertContains('COUNT', $res['operations']);
        $this->assertContains('GROUP BY', $res['operations']);
        $this->assertContains('ORDER BY DESC', $res['operations']);
    }

    /**
     * Test 25: Monthly revenue query passes semantic validation
     */
    public function test_semantic_validation_passes_for_monthly_revenue_query(): void
    {
        $validator = app(SqlSemanticValidator::class);
        $question = 'Show monthly revenue';
        $sql = "SELECT DATE_FORMAT(order_date, '%Y-%m') AS month, SUM(total_amount) AS revenue FROM orders GROUP BY DATE_FORMAT(order_date, '%Y-%m') ORDER BY month ASC";

        $res = $validator->validate($question, $sql);

        $this->assertTrue($res['valid']);
        $this->assertGreaterThanOrEqual(0.9, $res['score']);
        $this->assertNull($res['reason']);
        $this->assertContains('orders', $res['tables']);
        $this->assertContains('SUM', $res['operations']);
    }

    /**
     * Test 26: Semantic verifier handles malformed AI response safely
     */
    public function test_semantic_verifier_handles_malformed_ai_response_safely(): void
    {
        config(['services.ai.driver' => 'gemini']);
        config(['services.gemini.key' => 'mock-gemini-key']);

        // Mock Gemini returning non-JSON response for intent verification
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => 'Not valid JSON from verifier'
                        ]]
                    ]
                ]]
            ], 200)
        ]);

        $gemini = app(\App\Services\Ai\GeminiAiService::class);
        $validator = new SqlSemanticValidator(app(\App\Services\DatabaseSchemaService::class), $gemini);

        $res = $validator->validate('Arbitrary custom query?', 'SELECT * FROM products');

        $this->assertFalse($res['valid']);
        $this->assertEquals(0.0, $res['score']);
        $this->assertStringContainsString('malformed JSON', $res['reason']);
    }

    /**
     * Test 27: Semantic verifier handles timeout and API failure safely
     */
    public function test_semantic_verifier_handles_timeout_and_api_failure_safely(): void
    {
        config(['services.ai.driver' => 'gemini']);
        config(['services.gemini.key' => 'mock-gemini-key']);

        Http::fake([
            'generativelanguage.googleapis.com/*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Intent verification timeout');
            }
        ]);

        $gemini = app(\App\Services\Ai\GeminiAiService::class);
        $validator = new SqlSemanticValidator(app(\App\Services\DatabaseSchemaService::class), $gemini);

        $res = $validator->validate('Arbitrary custom query?', 'SELECT * FROM products');

        $this->assertFalse($res['valid']);
        $this->assertEquals(0.0, $res['score']);
        $this->assertStringContainsString('Intent verification timeout', $res['reason']);
    }
}
