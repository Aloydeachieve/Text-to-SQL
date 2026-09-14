<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QueryLog extends Model
{
    protected $fillable = [
        'user_id',
        'company_id',
        'database_connection_id',
        'source',
        'question',
        'generated_sql',
        'passed_guardrails',
        'execution_status',
        'execution_time_ms',
        'error_message',
        'confidence_score'
    ];

    protected $casts = [
        'passed_guardrails' => 'boolean',
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
