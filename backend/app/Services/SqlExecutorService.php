<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SqlExecutorService
{
    /**
     * Safely execute a read-only SELECT query and return results and execution time.
     *
     * @param string $sql
     * @param string|null $connectionName
     * @param array $bindings
     * @return array{success: bool, results: array, time_ms: float, error: ?string}
     */
    public function execute(string $sql, ?string $connectionName = null, array $bindings = []): array
    {
        // Defense-in-depth: Reject any query that does not start with SELECT or WITH
        if (!preg_match('/^\s*(?:SELECT|WITH)\b/i', $sql)) {
            return [
                'success' => false,
                'results' => [],
                'time_ms' => 0,
                'error' => 'Only read-only SELECT queries are permitted for execution.'
            ];
        }

        $startTime = microtime(true);
        
        try {
            // Run the query on the target database connection.
            $connection = $connectionName ? DB::connection($connectionName) : DB::connection();
            $results = $connection->select($sql, $bindings);
            
            // Map rows to associative arrays for frontend compatibility
            $normalizedResults = array_map(function ($row) {
                return (array) $row;
            }, $results);
            
            $endTime = microtime(true);
            $timeMs = round(($endTime - $startTime) * 1000, 2);

            return [
                'success' => true,
                'results' => $normalizedResults,
                'time_ms' => $timeMs,
                'error' => null
            ];
        } catch (\Exception $e) {
            $endTime = microtime(true);
            $timeMs = round(($endTime - $startTime) * 1000, 2);
            
            // Log raw database failure details securely on the server
            \Illuminate\Support\Facades\Log::error('SQL query execution exception', [
                'sql' => $sql,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $errorMessage = app()->environment('local', 'testing') 
                ? $e->getMessage() 
                : 'A database query execution error occurred. Please verify your SQL syntax.';

            return [
                'success' => false,
                'results' => [],
                'time_ms' => $timeMs,
                'error' => $errorMessage
            ];
        }
    }
}
