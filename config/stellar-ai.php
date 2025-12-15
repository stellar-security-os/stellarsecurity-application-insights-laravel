<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Insights configuration
    |--------------------------------------------------------------------------
    |
    | Prefer connection string. Fallback to instrumentation key if needed.
    |
    */

    'connection_string' => env(
        'STELLAR_AI_CONNECTION_STRING',
        env(
            'APPLICATIONINSIGHTS_CONNECTION_STRING',
            env('APPINSIGHTS_CONNECTION_STRING', '')
        )
    ),

    'instrumentation_key' => env(
        'STELLAR_AI_INSTRUMENTATION_KEY',
        env(
            'APPINSIGHTS_INSTRUMENTATIONKEY',
            env('APPINSIGHTS_INSTRUMENTATION_KEY', '')
        )
    ),

    /*
    |--------------------------------------------------------------------------
    | Telemetry behavior
    |--------------------------------------------------------------------------
    */

    // Queue is disabled by default to avoid silent data loss when workers are not running.
    'use_queue' => env('STELLAR_AI_USE_QUEUE', false),

    // Buffer limit before flush (helps reduce HTTP calls).
    'buffer_limit' => (int) env('STELLAR_AI_BUFFER_LIMIT', 10),

    // Flush telemetry automatically at the end of the request lifecycle.
    'auto_flush' => env('STELLAR_AI_AUTO_FLUSH', true),

    // Emit one trace per request so Azure Search shows activity (can increase volume).
    'trace_per_request' => env('STELLAR_AI_TRACE_PER_REQUEST', true),

    // Application role name shown in Azure.
    'role_name' => env('STELLAR_AI_ROLE_NAME', env('APP_NAME', 'stellar-app')),

];
