<?php

namespace App\Services;

use App\Services\Contracts\AiServiceInterface;
use App\Services\Ai\RuleBasedAiService;
use App\Services\Ai\GeminiAiService;
use App\Services\Ai\AiSqlResponse;
use Illuminate\Support\Manager;

class AiSqlManager extends Manager implements AiServiceInterface
{
    public function getDefaultDriver(): string
    {
        return config('services.ai.driver', 'local');
    }

    public function createLocalDriver(): AiServiceInterface
    {
        return new RuleBasedAiService();
    }

    public function createGeminiDriver(): AiServiceInterface
    {
        return $this->container->make(GeminiAiService::class);
    }

    public function generateSql(string $question): AiSqlResponse
    {
        return $this->driver()->generateSql($question);
    }
}
