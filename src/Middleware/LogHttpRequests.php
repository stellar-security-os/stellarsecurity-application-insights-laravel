<?php

namespace StellarSecurity\ApplicationInsightsLaravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use StellarSecurity\ApplicationInsightsLaravel\ApplicationInsights;
use StellarSecurity\ApplicationInsightsLaravel\Helpers\HttpExtractor;
use Throwable;

class LogHttpRequests
{
    public function __construct(
        protected ApplicationInsights $ai,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        $start = microtime(true);

        try {
            /** @var \Symfony\Component\HttpFoundation\Response $response */
            $response   = $next($request);
            $durationMs = (microtime(true) - $start) * 1000;
            $statusCode = $response->getStatusCode();

            // Track request (RequestData -> requests table)
            $this->ai->trackRequest(
                $request->getMethod(),
                HttpExtractor::url($request),
                $statusCode,
                $durationMs,
                [
                    'success'     => $statusCode < 500,
                    'http.method' => $request->getMethod(),
                    'http.path'   => $request->path(),
                    'http.route'  => optional($request->route())->uri(),
                    'http.status' => $statusCode,
                    'app.env'     => (string) config('app.env'),
                ]
            );

            return $response;
        } catch (Throwable $e) {
            // If an exception is thrown, log a failed request and rethrow.
            $durationMs = (microtime(true) - $start) * 1000;

            $this->ai->trackRequest(
                $request->getMethod(),
                HttpExtractor::url($request),
                500,
                $durationMs,
                [
                    'success'           => false,
                    'http.method'       => $request->getMethod(),
                    'http.path'         => $request->path(),
                    'http.route'        => optional($request->route())->uri(),
                    'http.status'       => 500,
                    'exception.type'    => get_class($e),
                    'exception.message' => $e->getMessage(),
                    'app.env'           => (string) config('app.env'),
                ]
            );

            throw $e;
        } finally {
            // Ensure telemetry is sent even when buffer_limit is not reached.
            // This prevents "missing" telemetry on low-traffic apps.
            if ((bool) config('stellar-ai.auto_flush', true)) {
                try {
                    $this->ai->flush();
                } catch (Throwable $flushError) {
                    // Telemetry must never break the application.
                }
            }
        }
    }
}
