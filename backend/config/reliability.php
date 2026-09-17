<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Query Execution & Result Size Limits
    |--------------------------------------------------------------------------
    |
    | Prevents queries from consuming excessive memory or hanging indefinitely.
    | Truncates returned rows to a safe maximum to protect both backend memory
    | and frontend browser rendering performance.
    |
    */

    'max_query_rows' => (int) env('QUERY_MAX_ROWS', 1000),

    'query_timeout_seconds' => (int) env('QUERY_TIMEOUT_SECONDS', 10),

    'export_max_rows' => (int) env('EXPORT_MAX_ROWS', 5000),

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting Policies
    |--------------------------------------------------------------------------
    |
    | Configurable requests-per-minute limits across distinct traffic profiles:
    | - api: standard authenticated API endpoints
    | - ai: natural-language SQL generation calls
    | - query: interactive or saved query execution against databases
    | - auth: burst protection on login and registration
    |
    */

    'rate_limits' => [
        'api' => (int) env('RATE_LIMIT_API', 120),
        'ai' => (int) env('RATE_LIMIT_AI', 20),
        'query' => (int) env('RATE_LIMIT_QUERY', 30),
        'auth' => (int) env('RATE_LIMIT_AUTH', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Health & Readiness Configuration
    |--------------------------------------------------------------------------
    |
    | Probe timeout parameters for system and customer connection health checks.
    |
    */

    'health_probe_timeout_seconds' => (int) env('HEALTH_PROBE_TIMEOUT_SECONDS', 3),
];
