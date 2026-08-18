<?php

$queryParamRulesJson = trim((string) env('STELLAR_AI_QUERY_PARAM_RULES', '{}'));
$queryParamRules = json_decode($queryParamRulesJson === '' ? '{}' : $queryParamRulesJson, true);

// Invalid JSON is represented as null. The sanitizer treats null as an invalid
// rules configuration and fails closed by masking query parameter values.
if (! is_array($queryParamRules)) {
    $queryParamRules = null;
}

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

    // Master switch for including URL query parameters in request telemetry.
    'include_query_params' => env('STELLAR_AI_INCLUDE_QUERY_PARAMS', false),

    /*
    |--------------------------------------------------------------------------
    | Query parameter privacy rules
    |--------------------------------------------------------------------------
    |
    | STELLAR_AI_QUERY_PARAM_RULES is JSON. Parameter names/patterns are object
    | keys. Matching is case-insensitive and supports * and ? wildcards.
    |
    | Example:
    | {
    |   "token": {"action":"mask"},
    |   "customer_id": {"action":"show_last","characters":4},
    |   "utm_*": {"action":"keep"}
    | }
    |
    | Supported actions: keep, mask, drop, show_first, show_last.
    | show_first/show_last require a non-negative "characters" value.
    | A rule may optionally define its own "mask" string.
    |
    */

    'query_param_rules' => $queryParamRules,

    // Apply the package's built-in sensitive-name protection after custom rules.
    // Custom rules always take precedence, so installers can explicitly override it.
    'query_param_use_builtin_sensitive_rules' => env(
        'STELLAR_AI_QUERY_PARAM_USE_BUILTIN_SENSITIVE_RULES',
        true
    ),

    // Action used for parameters that match neither a custom rule nor a built-in sensitive rule.
    'query_param_default_action' => env('STELLAR_AI_QUERY_PARAM_DEFAULT_ACTION', 'keep'),

    // Replacement marker used when a mask/show_first/show_last rule has no per-rule mask.
    'query_param_mask' => env('STELLAR_AI_QUERY_PARAM_MASK', '<removed>'),

    // Application role name shown in Azure.
    'role_name' => env('STELLAR_AI_ROLE_NAME', env('APP_NAME', 'stellar-app')),

];
