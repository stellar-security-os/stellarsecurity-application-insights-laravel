# Stellar Application Insights for Laravel

A lightweight Laravel package that sends telemetry to Azure Application Insights (requests, exceptions, and custom events).

Built by https://stellarsecurity.com

This package is designed to be safe by default:
- Telemetry **must never** break your application
- Queue sending is **disabled by default** to avoid silent data loss
- URL query parameters are **disabled by default** to reduce accidental exposure of request data
- Sensitive query parameter values are masked by default when query capture is enabled
- Query parameter privacy behavior can be controlled with `.env` rules
- Sensitive telemetry properties are masked before they are sent
- Connection String is the preferred configuration (modern App Insights)

## Requirements
- PHP >= 8.1
- Laravel 10+ (also compatible with Laravel 11/12 when using matching illuminate components)
- Guzzle 7.x or 8.x

## Installation

```bash
composer require stellarsecurity/application-insights-laravel
```

## Configuration

Publish the package config:

```bash
php artisan vendor:publish --tag=stellar-ai-config
```

Then configure the package through your `.env` file.

### Recommended: Connection String

Set one of the following in your `.env`:

```env
APPLICATIONINSIGHTS_CONNECTION_STRING=InstrumentationKey=xxxx;IngestionEndpoint=https://westeurope-0.in.applicationinsights.azure.com/
```

You may also use the package-specific key:

```env
STELLAR_AI_CONNECTION_STRING=InstrumentationKey=xxxx;IngestionEndpoint=https://westeurope-0.in.applicationinsights.azure.com/
```

### Fallback: Instrumentation Key only

```env
STELLAR_AI_INSTRUMENTATION_KEY=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
```

## Query parameters in request URLs

Query parameters are excluded from request telemetry by default.

For example, this incoming request:

```text
https://example.com/api/orders?status=pending&page=2
```

is recorded as:

```text
https://example.com/api/orders
```

To include query parameters in Application Insights request URLs, enable:

```env
STELLAR_AI_INCLUDE_QUERY_PARAMS=true
```

After changing telemetry configuration, rebuild Laravel's configuration cache:

```bash
php artisan config:clear
php artisan config:cache
```

### Query parameter privacy rules

When query parameter capture is enabled, each parameter can have its own JSON rule.

`STELLAR_AI_QUERY_PARAM_RULES` is a JSON object where each key is a parameter name or wildcard pattern and each value describes what should happen to that parameter:

```env
STELLAR_AI_QUERY_PARAM_RULES='{"token":{"action":"mask"},"email":{"action":"mask"},"customer_id":{"action":"show_last","characters":4},"reference":{"action":"show_first","characters":6},"utm_*":{"action":"keep"},"debug":{"action":"drop"}}'
```

The same value formatted as JSON is:

```json
{
  "token": {
    "action": "mask"
  },
  "email": {
    "action": "mask"
  },
  "customer_id": {
    "action": "show_last",
    "characters": 4
  },
  "reference": {
    "action": "show_first",
    "characters": 6
  },
  "utm_*": {
    "action": "keep"
  },
  "debug": {
    "action": "drop"
  }
}
```

Parameter matching is case-insensitive and supports `*` and `?` wildcards.

Supported actions:

| Action | Behavior |
|---|---|
| `keep` | Keep the original parameter value |
| `mask` | Replace the complete value with the configured mask marker |
| `drop` | Remove the parameter completely from the telemetry URL |
| `show_first` | Keep the first `characters` characters and mask the rest |
| `show_last` | Mask the beginning and keep the last `characters` characters |

`show_first` and `show_last` require a non-negative integer `characters` value.

Example request:

```text
https://example.com/api/orders?token=super-secret&customer_id=12345678&reference=ABCDEFGH&utm_source=google&debug=1
```

With:

```env
STELLAR_AI_INCLUDE_QUERY_PARAMS=true
STELLAR_AI_QUERY_PARAM_RULES='{"token":{"action":"mask"},"customer_id":{"action":"show_last","characters":4},"reference":{"action":"show_first","characters":6},"utm_*":{"action":"keep"},"debug":{"action":"drop"}}'
```

it is recorded as the equivalent of:

```text
https://example.com/api/orders?token=<removed>&customer_id=<removed>5678&reference=ABCDEF<removed>&utm_source=google
```

The mask marker is URL-encoded in the actual telemetry URL.

### Per-rule mask markers

A rule can override the global mask marker. This is useful when different parameters need different masking formats:

```env
STELLAR_AI_QUERY_PARAM_RULES='{"phone":{"action":"show_last","characters":4,"mask":"***"},"token":{"action":"mask","mask":"[redacted]"}}'
```

If a rule does not define `mask`, the package uses `STELLAR_AI_QUERY_PARAM_MASK`.

### Built-in sensitive rules

For backward compatibility and secure defaults, the package still masks parameter names containing sensitive fragments such as `password`, `token`, `authorization`, `auth`, `api_key`, `secret`, `email`, `username`, `ip`, `ip_address`, `user_agent`, and `wipe_token`.

Built-in rules are applied only when no custom rule matched. This means custom JSON rules can deliberately override them:

```env
STELLAR_AI_QUERY_PARAM_RULES='{"email":{"action":"keep"},"access_token":{"action":"show_last","characters":4}}'
```

To disable the built-in query parameter rules completely:

```env
STELLAR_AI_QUERY_PARAM_USE_BUILTIN_SENSITIVE_RULES=false
```

This setting only changes query parameter handling. The package's general telemetry-property sanitization remains protected by its built-in sensitive-key behavior.

### Default action for unmatched parameters

By default, unmatched non-sensitive query parameters are kept, preserving the package's previous behavior:

```env
STELLAR_AI_QUERY_PARAM_DEFAULT_ACTION=keep
```

The default action supports `keep`, `mask`, or `drop`. Partial masking belongs in a parameter-specific JSON rule because it requires a `characters` value.

A strict allowlist-style policy can mask everything except explicitly approved parameters:

```env
STELLAR_AI_INCLUDE_QUERY_PARAMS=true
STELLAR_AI_QUERY_PARAM_USE_BUILTIN_SENSITIVE_RULES=false
STELLAR_AI_QUERY_PARAM_DEFAULT_ACTION=mask
STELLAR_AI_QUERY_PARAM_RULES='{"page":{"action":"keep"},"status":{"action":"keep"},"order_id":{"action":"show_last","characters":4},"utm_*":{"action":"keep"}}'
```

This is the recommended configuration when you want explicit control over every query parameter that may appear in telemetry.

### Overlapping wildcard rules

For most installations, the JSON object format above is the simplest option. Rules are evaluated in their decoded order and the first matching rule wins.

If overlapping wildcard precedence must be explicit, an ordered JSON list is also accepted:

```env
STELLAR_AI_QUERY_PARAM_RULES='[{"parameter":"utm_secret_*","action":"mask"},{"parameter":"utm_*","action":"keep"}]'
```

Each list item uses `parameter` as the name/pattern plus the same `action`, `characters`, and optional `mask` fields.

### Custom global mask marker

The default mask marker is `<removed>`:

```env
STELLAR_AI_QUERY_PARAM_MASK="<removed>"
```

It can be changed, for example:

```env
STELLAR_AI_QUERY_PARAM_MASK="***"
```

A complete example:

```env
STELLAR_AI_INCLUDE_QUERY_PARAMS=true
STELLAR_AI_QUERY_PARAM_RULES='{"token":{"action":"mask"},"session_*":{"action":"mask"},"customer_id":{"action":"show_last","characters":4},"reference":{"action":"show_first","characters":6},"utm_*":{"action":"keep"},"debug":{"action":"drop"}}'
STELLAR_AI_QUERY_PARAM_USE_BUILTIN_SENSITIVE_RULES=true
STELLAR_AI_QUERY_PARAM_DEFAULT_ACTION=keep
STELLAR_AI_QUERY_PARAM_MASK="<removed>"
```

Invalid JSON fails closed: query parameter values are masked instead of being exposed. An explicitly matched rule with an invalid action or invalid `characters` value also fails closed to full masking. An invalid default action fails closed to `mask`.

## Example config (`config/stellar-ai.php`)

```php
<?php

$queryParamRulesJson = trim((string) env('STELLAR_AI_QUERY_PARAM_RULES', '{}'));
$queryParamRules = json_decode($queryParamRulesJson === '' ? '{}' : $queryParamRulesJson, true);

if (! is_array($queryParamRules)) {
    $queryParamRules = null;
}

return [

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

    'use_queue' => env('STELLAR_AI_USE_QUEUE', false),
    'buffer_limit' => (int) env('STELLAR_AI_BUFFER_LIMIT', 10),
    'auto_flush' => env('STELLAR_AI_AUTO_FLUSH', true),
    'trace_per_request' => env('STELLAR_AI_TRACE_PER_REQUEST', true),

    'include_query_params' => env('STELLAR_AI_INCLUDE_QUERY_PARAMS', false),
    'query_param_rules' => $queryParamRules,
    'query_param_use_builtin_sensitive_rules' => env(
        'STELLAR_AI_QUERY_PARAM_USE_BUILTIN_SENSITIVE_RULES',
        true
    ),
    'query_param_default_action' => env('STELLAR_AI_QUERY_PARAM_DEFAULT_ACTION', 'keep'),
    'query_param_mask' => env('STELLAR_AI_QUERY_PARAM_MASK', '<removed>'),

    'role_name' => env('STELLAR_AI_ROLE_NAME', env('APP_NAME', 'stellar-app')),

];
```

## Queue mode (optional)

By default, telemetry is sent directly (HTTP) to avoid losing data if no workers are running.

If you want to use queues:

```env
STELLAR_AI_USE_QUEUE=true
```

Then ensure a worker is running in production:

```bash
php artisan queue:work
```

If you enable queue mode without a running worker, telemetry will be delayed and may appear missing.

## What is tracked

Depending on your middleware/service wiring, the package can track:
- HTTP requests
- Exceptions
- Custom events (EventData)
- Dependencies (if you emit dependency telemetry)

## Viewing data in Azure

In Azure Portal -> Application Insights -> **Logs (Analytics)**, run:

```kusto
union requests, traces, exceptions
| order by timestamp desc
```

If you only want requests:

```kusto
requests
| order by timestamp desc
```

To inspect captured URLs and verify query parameter handling when enabled:

```kusto
requests
| project timestamp, name, url, resultCode, success
| order by timestamp desc
```

## Common troubleshooting

### I see no data at all

1. Confirm your app is using the **correct** Application Insights resource.
2. Confirm a valid **instrumentation key** is resolved.
   - If using a connection string, it must include `InstrumentationKey=...`.
3. If queue mode is enabled, confirm workers are running.
4. Clear and rebuild config cache after changing `.env`:

```bash
php artisan config:clear
php artisan config:cache
```

### Query parameters are missing

Query parameter capture is disabled by default. Enable it with:

```env
STELLAR_AI_INCLUDE_QUERY_PARAMS=true
```

Then rebuild the Laravel configuration cache:

```bash
php artisan config:clear
php artisan config:cache
```

### A query parameter is unexpectedly masked

Check these settings in this order:

1. `STELLAR_AI_QUERY_PARAM_RULES` - first matching custom rule wins.
2. `STELLAR_AI_QUERY_PARAM_USE_BUILTIN_SENSITIVE_RULES` - built-in sensitive-name protection runs after custom rules.
3. `STELLAR_AI_QUERY_PARAM_DEFAULT_ACTION` - applies when nothing else matched.

After editing `.env`, rebuild the Laravel configuration cache.

### Azure "Search" looks empty, but Logs has data

This is usually a UI filtering issue. Use Logs (Analytics) queries to confirm ingestion.

## Local verification

After installing dependencies, you can run the package's query-rule smoke test directly:

```bash
composer install
php tests/query_param_rules_smoke.php
```

There is no separate package build step. In a Laravel application, publish the configuration and rebuild Laravel's configuration cache after changing `.env` values:

```bash
php artisan vendor:publish --tag=stellar-ai-config
php artisan config:clear
php artisan config:cache
```

## License

MIT
