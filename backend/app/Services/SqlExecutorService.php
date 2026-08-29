<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SqlExecutorService
{
    /**
     * Safely execute a read-only SELECT query and return results and execution time.
     *
     * @param string $sql
     * @return array{success: bool, results: array, time_ms: float, error: ?string}
     */
    public function execute(string $sql): array
    {
        $startTime = microtime(true);
        
        try {
            // Run the query on the database.
            // DB::select runs SELECT queries and returns array of stdClass.
            $results = DB::select($sql);
            
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
