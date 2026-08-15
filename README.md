# Stellar Application Insights for Laravel

A lightweight Laravel package that sends telemetry to Azure Application Insights (requests, exceptions, and custom events).

Built by https://stellarsecurity.com

This package is designed to be safe by default:
- Telemetry **must never** break your application
- Queue sending is **disabled by default** to avoid silent data loss
- URL query parameters are **disabled by default** to reduce accidental exposure of request data
- Sensitive telemetry values are masked before they are sent
- Connection String is the preferred configuration (modern App Insights)

## Requirements
- PHP >= 8.1
- Laravel 10+ (also compatible with Laravel 11/12 when using matching illuminate components)
- Guzzle 7.x

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

After changing the value, rebuild Laravel's configuration cache:

```bash
php artisan config:clear
php artisan config:cache
```

When enabled, non-sensitive query parameters are retained:

```text
https://example.com/api/orders?status=pending&page=2
```

Sensitive query parameter values are still masked before telemetry is sent. For example:

```text
https://example.com/api/reset?token=secret-value&page=2
```

is recorded with the token value removed:

```text
https://example.com/api/reset?token=%3Cremoved%3E&page=2
```

This behavior applies to the request URL used by the HTTP request telemetry path, including failed requests captured by the middleware.

## Example config (`config/stellar-ai.php`)

```php
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

    // Include URL query parameters in request telemetry. Sensitive values are masked.
    'include_query_params' => env('STELLAR_AI_INCLUDE_QUERY_PARAMS', false),

    // Application role name shown in Azure.
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

To inspect captured URLs and verify query parameters when enabled:

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

Sensitive query values remain masked even when query parameter capture is enabled.

### Azure "Search" looks empty, but Logs has data

This is usually a UI filtering issue. Use Logs (Analytics) queries to confirm ingestion.

## License

MIT
