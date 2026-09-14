<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Schema Intelligence & Relevance Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration options controlling schema introspection limits,
    | relevance selection, and protection against oversized prompt contexts.
    |
    */

    'max_tables_in_context' => (int) env('SCHEMA_MAX_TABLES_IN_CONTEXT', 15),

    'max_columns_per_table' => (int) env('SCHEMA_MAX_COLUMNS_PER_TABLE', 30),

    'max_context_bytes' => (int) env('SCHEMA_MAX_CONTEXT_BYTES', 12000),

    'enable_relevance_filter' => (bool) env('SCHEMA_ENABLE_RELEVANCE_FILTER', true),

    'retry_on_schema_failure' => (bool) env('SCHEMA_RETRY_ON_FAILURE', true),

    /*
    |--------------------------------------------------------------------------
    | Excluded System & Internal Tables
    |--------------------------------------------------------------------------
    |
    | Database-driver aware list of system tables and framework-internal tables
    | that should be excluded from dynamic schema introspection and AI context.
    |
    */

    'excluded_tables' => [
        'common' => [
            'migrations',
            'personal_access_tokens',
            'failed_jobs',
            'job_batches',
            'jobs',
            'cache',
            'cache_locks',
            'sessions',
            'sqlite_sequence',
            'flyway_schema_history',
            'alembic_version',
            'schema_migrations',
        ],
        'mysql' => [
            'sys',
            'innodb_table_stats',
            'innodb_index_stats',
        ],
        'pgsql' => [
            'spatial_ref_sys',
            'geography_columns',
            'geometry_columns',
        ],
    ],

    'excluded_prefixes' => [
        'telescope_',
        'pulse_',
        'pg_',
        'raster_',
    ],
];
