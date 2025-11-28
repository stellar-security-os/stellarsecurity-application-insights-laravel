
# Stellar Security – Application Insights for Laravel

Built by [StellarSecurity.com](https://stellarsecurity.com)

Lightweight telemetry package for Laravel that sends structured events to your
observability backend (e.g. Azure Application Insights).

It can automatically track:

- Incoming HTTP requests
- Outgoing HTTP calls (Guzzle)
- Database slow queries
- Failed jobs
- Mail failures
- Custom AV / security events

## Installation

```bash
composer require stellarsecurity/application-insights-laravel
```

Laravel will auto-discover the service provider.

Then publish the config:

```bash
php artisan vendor:publish --tag=stellar-ai-config
```

Set your instrumentation key:

```env
STELLAR_AI_INSTRUMENTATION_KEY="446e6a5e-a5ca-4c50-b311-e82648d987ef"
```

If you use queues, enable queue mode (default = true):

```env
STELLAR_AI_USE_QUEUE=true
```

and run a worker:

```bash
php artisan queue:work
```

If you don’t use queues, the package will send telemetry synchronously.

---

## Enable automatic HTTP request tracking

In **`bootstrap/app.php`** (Laravel 11/12 style), register the HTTP middleware:

```php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Configuration\Exceptions;
use StellarSecurity\ApplicationInsightsLaravel\Middleware\LogHttpRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        // ...
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Append Stellar HTTP logging to the global stack
        $middleware->append(LogHttpRequests::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // see "Enable automatic exception tracking" below
    })
    ->create();
```

After this, every incoming HTTP request will be sent to Application Insights as telemetry
(method, URL, status code, duration, etc.).

---

## Enable automatic exception tracking

Still in **`bootstrap/app.php`**, wire the exception hook inside `withExceptions`:

```php
use Throwable;
use Illuminate\Foundation\Configuration\Exceptions;
use StellarSecurity\ApplicationInsightsLaravel\ApplicationInsights;

return Application::configure(basePath: dirname(__DIR__))
    // ...
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->report(function (Throwable $e) {
            // Send exceptions to Application Insights
            app(ApplicationInsights::class)->trackException($e);
        });
    })
    ->create();
```

Now every reported exception will be pushed to Application Insights as a custom event / exception telemetry.

---

## Basic manual usage

You can also send custom events from anywhere:

```php
use StellarSecurity\ApplicationInsightsLaravel\ApplicationInsights;

app(ApplicationInsights::class)->trackEvent('AV.HashCheck', [
    'client'  => 'Stellar Antivirus Desktop',
    'verdict' => 'malware',
]);
```

---

## What gets tracked automatically?

Once installed and configured:

- **HTTP requests** – via `LogHttpRequests` middleware
- **Exceptions** – via the `withExceptions` hook
- **(Optional) Queued jobs / DB / mail / dependencies** – depending on how you use the package in your app

Telemetry is batched and sent to the official Azure ingestion endpoint.

---

## About Stellar Security

Stellar Security builds privacy-focused security products (Stellar Antivirus, StellarOS, VPN and more) with Swiss-grade security.
