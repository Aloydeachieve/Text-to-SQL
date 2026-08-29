<?php

namespace App\Services\Contracts;

use App\Services\Ai\AiSqlResponse;

interface AiServiceInterface
{
    /**
     * Translate natural language to SQL.
     *
     * @param string $question
     * @return AiSqlResponse
     */
    public function generateSql(string $question): AiSqlResponse;
}
