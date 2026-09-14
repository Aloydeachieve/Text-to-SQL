<?php

namespace App\Services\Contracts;

use App\Services\Ai\AiSqlResponse;

interface AiServiceInterface
{
    /**
     * Translate natural language to SQL.
     *
     * @param string $question
     * @param string|null $schemaContext
     * @param string $driver
     * @return AiSqlResponse
     */
    public function generateSql(string $question, ?string $schemaContext = null, string $driver = 'mysql'): AiSqlResponse;

    /**
     * Regenerate SQL with specific schema/validation feedback after a recoverable validation failure.
     *
     * @param string $question
     * @param string|null $schemaContext
     * @param string $driver
     * @param string $failedSql
     * @param string $errorMessage
     * @return AiSqlResponse
     */
    public function generateSqlWithCorrection(string $question, ?string $schemaContext = null, string $driver = 'mysql', string $failedSql = '', string $errorMessage = ''): AiSqlResponse;
}
