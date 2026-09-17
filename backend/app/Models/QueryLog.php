<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QueryLog extends Model
{
    protected $fillable = [
        'request_id',
        'user_id',
        'company_id',
        'database_connection_id',
        'source',
        'question',
        'generated_sql',
        'passed_guardrails',
        'execution_status',
        'execution_time_ms',
        'rows_returned',
        'truncated',
        'risk_level',
        'error_message',
        'error_code',
        'confidence_score',
    ];

    protected $casts = [
        'passed_guardrails' => 'boolean',
        'truncated' => 'boolean',
        'rows_returned' => 'integer',
        'confidence_score' => 'float',
        'execution_time_ms' => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function databaseConnection()
    {
        return $this->belongsTo(DatabaseConnection::class);
    }
}
