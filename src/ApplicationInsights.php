<?php

namespace StellarSecurity\ApplicationInsightsLaravel;

use Throwable;
use StellarSecurity\ApplicationInsightsLaravel\Helpers\TelemetrySanitizer;

class ApplicationInsights
{
    public function __construct(
        protected TelemetrySender $sender,
    ) {}

    public function trackEvent(string $name, array $properties = []): void
    {
        $properties = TelemetrySanitizer::sanitizeProperties($properties);

        $this->sender->enqueue([
            'type' => 'event',
            'name' => $name,
            'time' => gmdate('c'),
            'properties' => $properties,
        ]);
    }

    public function trackException(Throwable $e, array $properties = []): void
    {
        $props = array_merge($properties, [
            'exception.type' => get_class($e),
            'exception.message' => $e->getMessage(),
            'exception.file' => $e->getFile(),
            'exception.line' => $e->getLine(),
        ]);

        $this->sender->enqueue([
            'type' => 'exception',
            'time' => gmdate('c'),
            'properties' => $props,
        ]);
    }

    public function trackRequest(
        string $method,
        string $url,
        int $statusCode,
        float $durationMs,
        array $properties = []
    ): void {

        // AI timespan format: "HH:MM:SS.mmm"
        $seconds  = (int) floor($durationMs / 1000);
        $millis   = (int) ($durationMs - ($seconds * 1000));
        $duration = sprintf('00:00:%02d.%03d', $seconds, $millis);

        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $name = sprintf('%s %s', strtoupper($method), $path);

        // 4xx/5xx = failed
        $success = $statusCode < 400;

        // Sanitize request properties before enqueuing
        $properties = TelemetrySanitizer::sanitizeProperties($properties);

        // Optionally emit a trace per request so Azure Search shows activity.
        // Enable/disable via STELLAR_AI_TRACE_PER_REQUEST (default true).
        if ((bool) config('stellar-ai.trace_per_request', true)) {
            $this->sender->enqueue([
                'name' => 'Microsoft.ApplicationInsights.Message',
                'time' => gmdate('c'),
                'data' => [
                    'baseType' => 'MessageData',
                    'baseData' => [
                        'ver' => 2,
                        'message' => 'HTTP request processed',
                        'severityLevel' => $success ? 1 : 3, // 1=Information, 3=Error
                        'properties' => array_merge($properties, [
                            'request.name'        => $name,
                            'request.method'      => $method,
                            'request.full_url'    => $url,
                            'request.status_code' => $statusCode,
                            'request.duration_ms' => (int) round($durationMs),
                        ]),
                    ],
                ],
            ]);
        }

        $item = [
            'name' => 'Microsoft.ApplicationInsights.Request',
            'data' => [
                'baseType' => 'RequestData',
                'baseData' => [
                    'ver'          => 2,
                    'id'           => bin2hex(random_bytes(8)),
                    'name'         => $name,
                    'duration'     => $duration,
                    'responseCode' => (string) $statusCode,
                    'success'      => $success,
                    'url'          => $url,
                    'properties'   => array_merge($properties, [
                        'request.method'       => $method,
                        'request.full_url'     => $url,
                        'request.status_code'  => $statusCode,
                        'request.duration_ms'  => $durationMs,
                    ]),
                ],
            ],
        ];

        $this->sender->enqueue($item);
    }

    public function trackDependency(
        string $target,
        string $name,
        float $durationMs,
        bool $success,
        array $properties = []
    ): void {
        $this->sender->enqueue([
            'type' => 'dependency',
            'time' => gmdate('c'),
            'properties' => array_merge($properties, [
                'dependency.target' => $target,
                'dependency.name' => $name,
                'dependency.duration_ms' => $durationMs,
                'dependency.success' => $success,
            ]),
        ]);
    }

    public function trackDbQuery(string $sql, float $durationMs, array $properties = []): void
    {
        $this->sender->enqueue([
            'type' => 'db',
            'time' => gmdate('c'),
            'properties' => array_merge($properties, [
                'db.sql' => $sql,
                'db.duration_ms' => $durationMs,
            ]),
        ]);
    }

    public function flush(): void
    {
        $this->sender->flush();
    }
}
