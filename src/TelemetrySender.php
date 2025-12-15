<?php

namespace StellarSecurity\ApplicationInsightsLaravel;

use GuzzleHttp\Client;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Facades\Log;
use StellarSecurity\ApplicationInsightsLaravel\Jobs\SendTelemetryJob;

class TelemetrySender
{
    protected array $buffer = [];

    /**
     * Buffer limit before flushing telemetry.
     * Use a sane default to reduce HTTP calls while avoiding data loss.
     */
    protected int $bufferLimit;

    public function __construct(
        protected ?QueueFactory $queue = null,
        protected ?Client $client = null,
    ) {
        $this->bufferLimit = (int) config('stellar-ai.buffer_limit', 10);
        if ($this->bufferLimit < 1) {
            $this->bufferLimit = 1;
        }
    }

    public function enqueue(array $item): void
    {
        $this->buffer[] = $item;

        if (count($this->buffer) >= $this->bufferLimit) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        $batch = $this->buffer;
        $this->buffer = [];

        // Queue is disabled by default to avoid silent data loss if workers are not running.
        $useQueue = (bool) config('stellar-ai.use_queue', false);

        if ($useQueue && $this->queue) {
            $this->queue->connection()->push(new SendTelemetryJob($batch));
            return;
        }

        $this->sendBatch($batch);
    }

    public function sendBatch(array $items): void
    {
        $conn = (string) config('stellar-ai.connection_string', '');
        $ikey = (string) config('stellar-ai.instrumentation_key', '');

        // Prefer connection string (modern App Insights). Fallback to instrumentation key.
        [$endpoint, $resolvedIkey] = $this->resolveEndpointAndKey($conn, $ikey);

        if ($resolvedIkey === '') {
            // No telemetry configured, just drop.
            // Note: Azure will drop telemetry without iKey anyway.
            return;
        }

        $payload = [];

        foreach ($items as $item) {
            // If the item is already a full AI envelope (RequestData / ExceptionData / etc),
            // send it as-is (only ensure iKey and time are present).
            if (isset($item['data']['baseType'])) {
                $envelope = $item;

                // Ensure iKey and time.
                if (empty($envelope['iKey'])) {
                    $envelope['iKey'] = $resolvedIkey;
                }

                if (empty($envelope['time'])) {
                    $envelope['time'] = gmdate('c');
                }

                $payload[] = $envelope;
                continue;
            }

            // Otherwise: wrap as a custom EventData (fallback).
            $payload[] = [
                'time' => $item['time'] ?? gmdate('c'),
                'name' => 'Microsoft.ApplicationInsights.Event',
                'iKey' => $resolvedIkey,
                'data' => [
                    'baseType' => 'EventData',
                    'baseData' => [
                        'ver'        => 2,
                        'name'       => $item['name'] ?? ($item['type'] ?? 'event'),
                        'properties' => $item['properties'] ?? [],
                    ],
                ],
            ];
        }

        $client = $this->client ?: new Client([
            'timeout' => 2.0,
        ]);

        try {
            $client->post($endpoint, [
                'json' => $payload,
            ]);
        } catch (\Throwable $e) {
            // Telemetry must never break the application. Log locally at debug level.
            Log::debug('Application Insights telemetry send failed: ' . $e->getMessage());
        }
    }

    /**
     * Resolve ingestion endpoint and instrumentation key from connection string and/or fallback key.
     *
     * Returns:
     *  - [endpointUrl, instrumentationKey]
     */
    protected function resolveEndpointAndKey(string $connectionString, string $fallbackIkey): array
    {
        $ikey = trim($fallbackIkey);
        $ingestionEndpoint = null;

        if (trim($connectionString) !== '') {
            $parsed = $this->parseConnectionString($connectionString);

            if (!empty($parsed['InstrumentationKey'])) {
                $ikey = trim((string) $parsed['InstrumentationKey']);
            }

            if (!empty($parsed['IngestionEndpoint'])) {
                $ingestionEndpoint = rtrim((string) $parsed['IngestionEndpoint'], '/');
            }
        }

        // Default endpoint if the connection string does not specify a regional endpoint.
        $base = $ingestionEndpoint ?: 'https://dc.services.visualstudio.com';
        $endpoint = $base . '/v2/track';

        return [$endpoint, $ikey];
    }

    /**
     * Parse a connection string formatted like "Key=Value;Key2=Value2".
     */
    protected function parseConnectionString(string $connectionString): array
    {
        $result = [];

        foreach (explode(';', $connectionString) as $segment) {
            $segment = trim($segment);

            if ($segment === '' || !str_contains($segment, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $segment, 2);
            $result[trim($key)] = trim($value);
        }

        return $result;
    }
}
