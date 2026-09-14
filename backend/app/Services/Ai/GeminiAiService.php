<?php

namespace App\Services\Ai;

use App\Services\Contracts\AiServiceInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\DatabaseSchemaService;

class GeminiAiService implements AiServiceInterface
{
    protected ?string $apiKey;
    protected string $model;
    protected int $timeout;
    protected int $connectTimeout;
    protected DatabaseSchemaService $schemaService;

    public function __construct(DatabaseSchemaService $schemaService)
    {
        $this->apiKey = config('services.gemini.key');
        $this->model = config('services.gemini.model', 'gemini-3.5-flash');
        $this->timeout = (int) config('services.gemini.timeout', 15);
        $this->connectTimeout = (int) config('services.gemini.connect_timeout', 5);
        $this->schemaService = $schemaService;
    }

    /**
     * Sanitize sensitive credentials from error strings or logs.
     */
    protected function sanitize(string $text): string
    {
        if (!empty($this->apiKey)) {
            $text = str_replace($this->apiKey, '[REDACTED]', $text);
        }
        return preg_replace('/AIza[0-9A-Za-z\-_]{35}/', '[REDACTED]', $text) ?? $text;
    }

    public function generateSql(string $question, ?string $schemaContext = null, string $driver = 'mysql'): AiSqlResponse
    {
        if (empty($this->apiKey)) {
            return new AiSqlResponse(
                success: false,
                error: "Gemini API key is not configured. Please add GEMINI_API_KEY to your .env file."
            );
        }

        $schemaContext = $schemaContext ?? $this->schemaService->getPromptContext();
        $prompt = $this->buildPrompt($question, $schemaContext, $driver);

        return $this->executeGeneration($prompt);
    }

    /**
     * Regenerate SQL with specific schema/validation feedback after a recoverable validation failure.
     */
    public function generateSqlWithCorrection(string $question, ?string $schemaContext = null, string $driver = 'mysql', string $failedSql = '', string $errorMessage = ''): AiSqlResponse
    {
        if (empty($this->apiKey)) {
            return new AiSqlResponse(
                success: false,
                error: "Gemini API key is not configured. Please add GEMINI_API_KEY to your .env file."
            );
        }

        $schemaContext = $schemaContext ?? $this->schemaService->getPromptContext();
        $prompt = $this->buildCorrectionPrompt($question, $schemaContext, $driver, $failedSql, $errorMessage);

        return $this->executeGeneration($prompt);
    }

    /**
     * Send prompt to Gemini API and parse structured JSON response.
     */
    protected function executeGeneration(string $prompt): AiSqlResponse
    {
        try {
            $response = Http::timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $this->apiKey,
                ])
                ->post("https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent", [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt]
                            ]
                        ]
                    ],
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
                        'temperature' => 0.1,
                    ]
                ]);

            if ($response->failed()) {
                Log::error('Gemini API call failed', [
                    'status' => $response->status(),
                    'body' => $this->sanitize($response->body())
                ]);
                
                $errorMessage = app()->environment('local', 'testing') 
                    ? "Failed to communicate with Gemini API: " . $this->sanitize($response->json('error.message') ?? $response->reason())
                    : "Gemini API gateway error. Please try again later.";

                return new AiSqlResponse(
                    success: false,
                    error: $errorMessage
                );
            }

            $result = $response->json();
            $responseText = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

            if (empty($responseText)) {
                return new AiSqlResponse(
                    success: false,
                    error: "Empty response received from Gemini API."
                );
            }

            $jsonData = json_decode(trim($responseText), true);

            if (json_last_error() !== JSON_ERROR_NONE || !isset($jsonData['sql'])) {
                Log::error('Failed to parse Gemini response as JSON', ['raw_response' => $responseText]);
                return new AiSqlResponse(
                    success: false,
                    error: "AI returned malformed JSON or missing SQL query."
                );
            }

            return new AiSqlResponse(
                success: true,
                sql: $jsonData['sql'],
                confidence: (float)($jsonData['confidence'] ?? 0.8),
                explanation: $jsonData['explanation'] ?? 'Query generated by Gemini.'
            );

        } catch (\Exception $e) {
            $sanitizedMsg = $this->sanitize($e->getMessage());
            Log::error('Gemini Service Exception', [
                'exception' => $sanitizedMsg,
            ]);
            
            $errorMessage = app()->environment('local', 'testing')
                ? "An unexpected error occurred: " . $sanitizedMsg
                : "AI SQL translation is temporarily unavailable. Please try again later.";

            return new AiSqlResponse(
                success: false,
                error: $errorMessage
            );
        }
    }

    /**
     * Verify whether the generated SQL corresponds to the user's question intent.
     *
     * @param string $question
     * @param string $sql
     * @param array $structure
     * @param string|null $schemaContext
     * @return array{valid: bool, score: float, interpretation: string, reason: ?string}
     */
    public function verifyIntent(string $question, string $sql, array $structure, ?string $schemaContext = null): array
    {
        if (empty($this->apiKey)) {
            return [
                'valid' => false,
                'score' => 0.0,
                'reason' => 'Gemini API key is not configured.',
                'interpretation' => 'Missing Gemini credentials for semantic verification.'
            ];
        }

        $schemaContext = $schemaContext ?? $this->schemaService->getPromptContext();
        $prompt = $this->buildVerificationPrompt($question, $sql, $schemaContext, $structure);

        try {
            $response = Http::timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $this->apiKey,
                ])
                ->post("https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent", [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt]
                            ]
                        ]
                    ],
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
                        'temperature' => 0.0,
                    ]
                ]);

            if ($response->failed()) {
                Log::error('Gemini Intent Verification call failed', [
                    'status' => $response->status(),
                    'body' => $this->sanitize($response->body())
                ]);

                $errorMessage = app()->environment('local', 'testing')
                    ? "Failed to communicate with Gemini API: " . $this->sanitize($response->json('error.message') ?? $response->reason())
                    : "Semantic verification gateway error.";

                return [
                    'valid' => false,
                    'score' => 0.0,
                    'reason' => $errorMessage,
                    'interpretation' => 'Semantic verification could not be completed.'
                ];
            }

            $result = $response->json();
            $responseText = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

            if (empty($responseText)) {
                return [
                    'valid' => false,
                    'score' => 0.0,
                    'reason' => 'Empty response received from Gemini intent verification.',
                    'interpretation' => 'Semantic verification returned empty response.'
                ];
            }

            $jsonData = json_decode(trim($responseText), true);

            if (json_last_error() !== JSON_ERROR_NONE || !isset($jsonData['valid']) || !isset($jsonData['interpretation'])) {
                Log::error('Failed to parse Gemini intent verification response as JSON', ['raw_response' => $responseText]);
                return [
                    'valid' => false,
                    'score' => 0.0,
                    'reason' => 'AI returned malformed JSON during semantic verification.',
                    'interpretation' => 'Malformed semantic verification output.'
                ];
            }

            return [
                'valid' => (bool)$jsonData['valid'],
                'score' => (float)($jsonData['score'] ?? ($jsonData['valid'] ? 0.95 : 0.3)),
                'reason' => $jsonData['reason'] ?? ($jsonData['valid'] ? null : 'Query does not match the requested intent.'),
                'interpretation' => $jsonData['interpretation']
            ];

        } catch (\Exception $e) {
            $sanitizedMsg = $this->sanitize($e->getMessage());
            Log::error('Gemini Intent Verification Exception', [
                'exception' => $sanitizedMsg,
            ]);

            $errorMessage = app()->environment('local', 'testing')
                ? "Intent verification error: " . $sanitizedMsg
                : "Semantic verification is temporarily unavailable.";

            return [
                'valid' => false,
                'score' => 0.0,
                'reason' => $errorMessage,
                'interpretation' => 'Semantic verification failed.'
            ];
        }
    }

    protected function buildVerificationPrompt(string $question, string $sql, string $schemaContext, array $structure): string
    {
        $structureJson = json_encode($structure, JSON_PRETTY_PRINT);
        return <<<PROMPT
You are a SQL Query Intent and Semantic Verification engine.
Evaluate whether the provided SQL query accurately and specifically answers the user's natural language question based on the provided database schema.

Database Schema:
{$schemaContext}

User Question:
"{$question}"

Generated SQL Query:
{$sql}

Extracted Query Structure:
{$structureJson}

Instructions:
1. Determine if the SQL structurally and semantically answers the user's specific request.
   - Example 1: If the user asks "Which customers placed the most orders?", the SQL MUST identify and rank individual customers (e.g. JOIN customers and orders, GROUP BY customer, ORDER BY count DESC). If the SQL merely calculates COUNT(*) from orders, it does NOT answer the user's question.
   - Example 2: If the user asks "How many customers do we have?", the SQL should COUNT from customers.
2. Respond ONLY with a valid JSON object matching this schema:
{
  "valid": true or false,
  "score": float between 0.0 and 1.0 (confidence of semantic match),
  "interpretation": "A clear, concise 1-sentence description of what this SQL query actually calculates or retrieves.",
  "reason": null if valid, or a concise sentence explaining why the SQL does not answer the user's question if invalid.
}
PROMPT;
    }

    protected function buildCorrectionPrompt(string $question, string $schemaContext, string $driver, string $failedSql, string $errorMessage): string
    {
        $dialect = in_array(strtolower($driver), ['pgsql', 'postgres', 'postgresql']) ? 'PostgreSQL' : 'MySQL';

        return <<<PROMPT
You are a highly accurate SQL correction assistant.
Your previous SQL query for the user's question failed schema validation.

User Question:
"{$question}"

Database Dialect: {$dialect}

Database Schema:
{$schemaContext}

Previous SQL Generated:
{$failedSql}

Validation Error:
{$errorMessage}

Instructions:
1. Carefully analyze the validation error and the schema.
2. Fix any unknown table or column references, incorrect aliases, or invalid join conditions so that all referenced tables and columns exist in the provided schema.
3. You must respond ONLY with a JSON object containing the fields: "sql" (string), "confidence" (float), and "explanation" (string).
4. The generated SQL must be a valid, single SELECT query adhering strictly to {$dialect} syntax. Do not include semicolons.

JSON Format:
{
  "sql": "SELECT ...",
  "confidence": 0.95,
  "explanation": "Corrected column references to match schema."
}
PROMPT;
    }

    protected function buildPrompt(string $question, string $schemaContext, string $driver = 'mysql'): string
    {
        $isPgsql = in_array(strtolower($driver), ['pgsql', 'postgres', 'postgresql']);
        $dialect = $isPgsql ? 'PostgreSQL' : 'MySQL';

        $monthlyExample = $isPgsql
            ? 'SELECT DATE_TRUNC(\'month\', order_date) AS month, SUM(total_amount) AS revenue FROM orders GROUP BY DATE_TRUNC(\'month\', order_date) ORDER BY month ASC'
            : 'SELECT DATE_FORMAT(order_date, \'%Y-%m\') AS month, SUM(total_amount) AS revenue FROM orders GROUP BY DATE_FORMAT(order_date, \'%Y-%m\') ORDER BY month ASC';

        return <<<PROMPT
You are a highly secure and accurate SQL generator. Translate the user's natural language question into a single valid {$dialect} SELECT query based strictly on the provided schema.

Database Schema:
{$schemaContext}

Instructions:
1. You must respond ONLY with a JSON object containing the fields: "sql" (string), "confidence" (float, between 0.0 and 1.0), and "explanation" (string).
2. The generated SQL must be a valid, single SELECT query. Do not execute or generate DDL (CREATE, DROP, ALTER) or DML (INSERT, UPDATE, DELETE).
3. Do not include semicolons at the end of the SQL query.
4. Ensure joins are correct and table aliases are used for clarity.
5. Adhere to {$dialect} syntax conventions:
   - MySQL date functions: DATE_FORMAT, CURDATE, NOW, DATEDIFF, DATE_SUB(CURDATE(), INTERVAL 1 MONTH).
   - PostgreSQL date functions: DATE_TRUNC, CURRENT_DATE, CURRENT_TIMESTAMP, NOW, col >= CURRENT_DATE - INTERVAL '1 month', EXTRACT.
6. If the question cannot be answered with the given schema, set success: false or provide an appropriate error explanation.

JSON Format:
{
  "sql": "SELECT ...",
  "confidence": 0.95,
  "explanation": "This query joins table A and table B on field X, filters by Y, and calculates the sum of Z."
}

Few-Shot Examples:

Question: "How many customers do we have?"
Response:
{
  "sql": "SELECT COUNT(*) AS total_customers FROM customers",
  "confidence": 0.99,
  "explanation": "Counts the total number of records in the customers table."
}

Question: "What is the total revenue by month?"
Response:
{
  "sql": "{$monthlyExample}",
  "confidence": 0.98,
  "explanation": "Extracts the year and month from order_date, sums the order amounts, and groups chronologically."
}

Question: "Which products have generated the most revenue?"
Response:
{
  "sql": "SELECT p.id, p.name, SUM(oi.total_price) AS total_revenue FROM products p JOIN order_items oi ON p.id = oi.product_id GROUP BY p.id, p.name ORDER BY total_revenue DESC",
  "confidence": 0.97,
  "explanation": "Joins products with order items, aggregates total item price per product, and sorts in descending order."
}

Question: "What is the average order value?"
Response:
{
  "sql": "SELECT AVG(total_amount) AS average_order_value FROM orders",
  "confidence": 0.99,
  "explanation": "Calculates the average total amount across all placed orders."
}

User Question: "{$question}"
PROMPT;
    }
}
