<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SqlExecutorService
{
    /**
     * Safely execute a read-only SELECT query and return results, execution time, and reliability metadata.
     *
     * @param string $sql
     * @param string|null $connectionName
     * @param array $bindings
     * @return array{
     *     success: bool,
     *     results: array,
     *     time_ms: float,
     *     truncated: bool,
     *     returned_rows: int,
     *     limit: int,
     *     total_rows: int,
     *     error: ?string,
     *     error_code: ?string
     * }
     */
    public function execute(string $sql, ?string $connectionName = null, array $bindings = []): array
    {
        $maxRows = (int) config('reliability.max_query_rows', 1000);
        $timeoutSeconds = (int) config('reliability.query_timeout_seconds', 10);

        // Defense-in-depth: Reject any query that does not start with SELECT or WITH
        if (!preg_match('/^\s*(?:SELECT|WITH)\b/i', $sql)) {
            return [
                'success' => false,
                'results' => [],
                'time_ms' => 0,
                'truncated' => false,
                'returned_rows' => 0,
                'limit' => $maxRows,
                'total_rows' => 0,
                'error' => 'Only read-only SELECT queries are permitted for execution.',
                'error_code' => 'GUARDRAIL_BLOCKED',
            ];
        }

        $startTime = microtime(true);
        
        try {
            // Run the query on the target database connection.
            $connection = $connectionName ? DB::connection($connectionName) : DB::connection();
            $driver = strtolower($connection->getDriverName());

            // Apply driver-level session timeouts where supported
            $timeoutMs = $timeoutSeconds * 1000;
            if ($driver === 'mysql') {
                try {
                    $connection->statement("SET SESSION max_execution_time = {$timeoutMs}");
                } catch (Throwable) {
                    // Fail open if user lack privilege to set session variables
                }
            } elseif ($driver === 'pgsql') {
                try {
                    $connection->statement("SET SESSION statement_timeout = '{$timeoutMs}ms'");
                } catch (Throwable) {
                    // Fail open if user lacks privilege
                }
            }

            $results = $connection->select($sql, $bindings);
            $totalRows = count($results);

            // Result Size Protection: Truncate large result sets to protect backend & browser memory
            $truncated = false;
            $returnedRows = $totalRows;
            if ($totalRows > $maxRows) {
                $truncated = true;
                $returnedRows = $maxRows;
                $results = array_slice($results, 0, $maxRows);
            }
            
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
                'truncated' => $truncated,
                'returned_rows' => $returnedRows,
                'limit' => $maxRows,
                'total_rows' => $totalRows,
                'error' => null,
                'error_code' => null,
            ];
        } catch (Throwable $e) {
            $endTime = microtime(true);
            $timeMs = round(($endTime - $startTime) * 1000, 2);
            
            $errorLower = strtolower($e->getMessage());
            $isTimeout = str_contains($errorLower, 'max_execution_time')
                || str_contains($errorLower, 'statement timeout')
                || str_contains($errorLower, 'statement_timeout')
                || str_contains($errorLower, 'query execution was interrupted')
                || str_contains($errorLower, 'timed out')
                || $e->getCode() == 3024
                || $e->getCode() === '57014';

            $errorCode = $isTimeout ? 'DATABASE_QUERY_TIMEOUT' : 'DATABASE_QUERY_ERROR';

            // Log raw database failure details securely on the server
            Log::error('SQL query execution exception', [
                'sql' => $sql,
                'error_code' => $errorCode,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            if ($isTimeout) {
                $errorMessage = "Query execution timed out after {$timeoutSeconds} seconds. Please refine your query with more specific filters or a smaller date range.";
            } else {
                $errorMessage = app()->environment('local', 'testing') 
                    ? $e->getMessage() 
                    : 'A database query execution error occurred. Please verify your SQL syntax.';
            }

            return [
                'success' => false,
                'results' => [],
                'time_ms' => $timeMs,
                'truncated' => false,
                'returned_rows' => 0,
                'limit' => $maxRows,
                'total_rows' => 0,
                'error' => $errorMessage,
                'error_code' => $errorCode,
            ];
        }
    }
}
