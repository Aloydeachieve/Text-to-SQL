<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QueryLog extends Model
{
    protected $fillable = [
        'question',
        'generated_sql',
        'passed_guardrails',
        'execution_status',
        'execution_time_ms',
        'error_message',
        'confidence_score'
    ];
}
