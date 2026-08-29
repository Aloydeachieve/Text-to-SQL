<?php

namespace App\Services\Ai;

class AiSqlResponse
{
    public function __construct(
        public bool $success,
        public ?string $sql = null,
        public ?float $confidence = null,
        public ?string $explanation = null,
        public ?string $error = null
    ) {}
}
