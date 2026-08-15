<?php

namespace StellarSecurity\ApplicationInsightsLaravel\Helpers;

class TelemetrySanitizer
{
    /**
     * Keys that should never be sent in cleartext to telemetry.
     */
    private const SENSITIVE_FRAGMENTS = [
        'password',
        'token',
        'authorization',
        'auth',
        'api_key',
        'secret',
        'email',
        'username',
        'ip',
        'ip_address',
        'user_agent',
        'wipe_token'
    ];

    /**
     * Sanitize a flat or nested properties array.
     */
    public static function sanitizeProperties(array $properties): array
    {
        $clean = [];

        foreach ($properties as $key => $value) {
            $lowerKey = strtolower((string) $key);

            // If the key is sensitive, mask it completely.
            if (self::isSensitiveKey($lowerKey)) {
                $clean[$key] = '<removed>';
                continue;
            }

            // Recurse on nested arrays.
            if (is_array($value)) {
                $clean[$key] = self::sanitizeProperties($value);
                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    /**
     * Sanitize a raw URL query string while preserving non-sensitive parameters,
     * ordering, duplicate keys, and their original encoding.
     */
    public static function sanitizeQueryString(string $queryString): string
    {
        if ($queryString === '') {
            return '';
        }

        $segments = explode('&', $queryString);
        $clean = [];

        foreach ($segments as $segment) {
            if ($segment === '') {
                $clean[] = $segment;
                continue;
            }

            [$rawKey] = array_pad(explode('=', $segment, 2), 2, null);
            $decodedKey = strtolower(urldecode($rawKey));

            if (self::isSensitiveKey($decodedKey)) {
                $clean[] = $rawKey . '=%3Cremoved%3E';
                continue;
            }

            $clean[] = $segment;
        }

        return implode('&', $clean);
    }

    /**
     * Sanitize a full telemetry item (both "envelope" and simple typed items).
     */
    public static function sanitizeItem(array $item): array
    {
        // Top-level properties (the "typed" items your ApplicationInsights class enqueues).
        if (isset($item['properties']) && is_array($item['properties'])) {
            $item['properties'] = self::sanitizeProperties($item['properties']);
        }

        // Full AI envelope shape with baseData.properties.
        if (isset($item['data']['baseData']['properties']) && is_array($item['data']['baseData']['properties'])) {
            $item['data']['baseData']['properties'] = self::sanitizeProperties(
                $item['data']['baseData']['properties']
            );
        }

        return $item;
    }

    private static function isSensitiveKey(string $lowerKey): bool
    {
        foreach (self::SENSITIVE_FRAGMENTS as $fragment) {
            if (str_contains($lowerKey, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
