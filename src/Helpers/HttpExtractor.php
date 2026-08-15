<?php

namespace StellarSecurity\ApplicationInsightsLaravel\Helpers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HttpExtractor
{
    public static function method(Request $request): string
    {
        return $request->getMethod();
    }

    public static function url(Request $request): string
    {
        $baseUrl = $request->url();

        if (! (bool) config('stellar-ai.include_query_params', false)) {
            return $baseUrl;
        }

        $fullUrl = $request->fullUrl();
        $queryString = parse_url($fullUrl, PHP_URL_QUERY);

        if (! is_string($queryString) || $queryString === '') {
            return $baseUrl;
        }

        $sanitizedQueryString = TelemetrySanitizer::sanitizeQueryString($queryString);

        if ($sanitizedQueryString === '') {
            return $baseUrl;
        }

        return $baseUrl . '?' . $sanitizedQueryString;
    }

    public static function statusCode(Response $response): int
    {
        return $response->getStatusCode();
    }
}
