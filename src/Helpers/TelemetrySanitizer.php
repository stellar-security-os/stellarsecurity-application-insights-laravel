<?php

namespace StellarSecurity\ApplicationInsightsLaravel\Helpers;

class TelemetrySanitizer
{
    /**
     * Keys that should never be sent in cleartext to telemetry by default.
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
        'wipe_token',
    ];

    /**
     * Sanitize a flat or nested properties array.
     */
    public static function sanitizeProperties(array $properties): array
    {
        $clean = [];

        foreach ($properties as $key => $value) {
            $lowerKey = strtolower((string) $key);

            // Property sanitization keeps the package's strict built-in protection.
            if (self::isSensitiveKey($lowerKey)) {
                $clean[$key] = '<removed>';
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = self::sanitizeProperties($value);
                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    /**
     * Sanitize a raw URL query string using installer-defined privacy rules.
     *
     * Custom rules are evaluated first. If no custom rule matches, built-in
     * sensitive-name protection is applied when enabled. Finally,
     * query_param_default_action is used.
     *
     * An invalid JSON rules configuration fails closed by masking values.
     * Unchanged parameters retain their original encoding, ordering, and duplicates.
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

            [$rawKey, $rawValue] = array_pad(explode('=', $segment, 2), 2, null);
            $decodedKey = urldecode($rawKey);
            $action = self::resolveQueryParamAction($decodedKey);

            if ($action['type'] === 'drop') {
                continue;
            }

            if ($action['type'] === 'keep') {
                $clean[] = $segment;
                continue;
            }

            $decodedValue = $rawValue === null ? '' : urldecode($rawValue);
            $maskedValue = self::applyQueryParamAction($decodedValue, $action);
            $clean[] = $rawKey . '=' . urlencode($maskedValue);
        }

        return implode('&', $clean);
    }

    /**
     * Sanitize a full telemetry item (both "envelope" and simple typed items).
     */
    public static function sanitizeItem(array $item): array
    {
        if (isset($item['properties']) && is_array($item['properties'])) {
            $item['properties'] = self::sanitizeProperties($item['properties']);
        }

        if (isset($item['data']['baseData']['properties']) && is_array($item['data']['baseData']['properties'])) {
            $item['data']['baseData']['properties'] = self::sanitizeProperties(
                $item['data']['baseData']['properties']
            );
        }

        return $item;
    }

    /**
     * Resolve the action for a query parameter name.
     *
     * @return array{type:string,count?:int,mask?:string}
     */
    private static function resolveQueryParamAction(string $key): array
    {
        $parsedRules = self::parseQueryParamRules();

        // Invalid JSON/configuration fails closed for every query parameter.
        if (! $parsedRules['valid']) {
            return ['type' => 'mask'];
        }

        foreach ($parsedRules['rules'] as $rule) {
            if (self::matchesPattern($key, $rule['pattern'])) {
                return $rule['action'];
            }
        }

        if (
            (bool) config('stellar-ai.query_param_use_builtin_sensitive_rules', true)
            && self::isSensitiveKey(strtolower($key))
        ) {
            return ['type' => 'mask'];
        }

        $defaultAction = self::parseDefaultAction(
            (string) config('stellar-ai.query_param_default_action', 'keep')
        );

        // Fail closed for an invalid default action.
        return $defaultAction ?? ['type' => 'mask'];
    }

    /**
     * Parse JSON query parameter rules.
     *
     * Preferred JSON format:
     * {
     *   "token": {"action":"mask"},
     *   "customer_id": {"action":"show_last","characters":4},
     *   "utm_*": {"action":"keep"}
     * }
     *
     * An ordered list is also accepted for cases where overlapping wildcard
     * precedence must be explicit:
     * [
     *   {"parameter":"utm_secret_*","action":"mask"},
     *   {"parameter":"utm_*","action":"keep"}
     * ]
     *
     * @return array{valid:bool,rules:array<int,array{pattern:string,action:array{type:string,count?:int,mask?:string}}>}
     */
    private static function parseQueryParamRules(): array
    {
        $configuredRules = config('stellar-ai.query_param_rules', []);

        // config/stellar-ai.php intentionally stores null when JSON decoding fails.
        if ($configuredRules === null) {
            return [
                'valid' => false,
                'rules' => [],
            ];
        }

        // Support direct string configuration in tests/custom integrations while
        // keeping the package config itself decoded to an array.
        if (is_string($configuredRules)) {
            $raw = trim($configuredRules);

            if ($raw === '') {
                return [
                    'valid' => true,
                    'rules' => [],
                ];
            }

            $configuredRules = json_decode($raw, true);

            if (! is_array($configuredRules)) {
                return [
                    'valid' => false,
                    'rules' => [],
                ];
            }
        }

        if (! is_array($configuredRules)) {
            return [
                'valid' => false,
                'rules' => [],
            ];
        }

        $rules = [];

        if (array_is_list($configuredRules)) {
            foreach ($configuredRules as $ruleConfig) {
                if (! is_array($ruleConfig)) {
                    continue;
                }

                $pattern = trim((string) ($ruleConfig['parameter'] ?? $ruleConfig['pattern'] ?? ''));

                if ($pattern === '') {
                    continue;
                }

                $rules[] = [
                    'pattern' => $pattern,
                    'action' => self::parseRuleAction($ruleConfig) ?? ['type' => 'mask'],
                ];
            }
        } else {
            foreach ($configuredRules as $pattern => $ruleConfig) {
                $pattern = trim((string) $pattern);

                if ($pattern === '') {
                    continue;
                }

                $rules[] = [
                    'pattern' => $pattern,
                    'action' => self::parseRuleAction($ruleConfig) ?? ['type' => 'mask'],
                ];
            }
        }

        return [
            'valid' => true,
            'rules' => $rules,
        ];
    }

    /**
     * Parse a structured JSON rule.
     *
     * Examples:
     * {"action":"keep"}
     * {"action":"mask","mask":"***"}
     * {"action":"show_last","characters":4}
     * {"action":"show_first","characters":6,"mask":"[hidden]"}
     *
     * For convenience, "keep", "mask", and "drop" may also be used directly
     * as the JSON value for a parameter pattern.
     *
     * @return array{type:string,count?:int,mask?:string}|null
     */
    private static function parseRuleAction(mixed $ruleConfig): ?array
    {
        if (is_string($ruleConfig)) {
            $type = strtolower(trim($ruleConfig));

            if (in_array($type, ['keep', 'mask', 'drop'], true)) {
                return ['type' => $type];
            }

            return null;
        }

        if (! is_array($ruleConfig)) {
            return null;
        }

        $type = strtolower(trim((string) ($ruleConfig['action'] ?? '')));

        if (! in_array($type, ['keep', 'mask', 'drop', 'show_first', 'show_last'], true)) {
            return null;
        }

        $action = ['type' => $type];

        if (array_key_exists('mask', $ruleConfig)) {
            if (! is_string($ruleConfig['mask'])) {
                return null;
            }

            $action['mask'] = $ruleConfig['mask'];
        }

        if (in_array($type, ['show_first', 'show_last'], true)) {
            $characters = $ruleConfig['characters'] ?? null;

            if (! is_int($characters) || $characters < 0) {
                return null;
            }

            $action['count'] = $characters;
        }

        return $action;
    }

    /**
     * Parse the default action for unmatched parameters.
     *
     * The default action intentionally remains simple because partial masking
     * requires a per-parameter character count and belongs in JSON rules.
     *
     * @return array{type:string}|null
     */
    private static function parseDefaultAction(string $rawAction): ?array
    {
        $action = strtolower(trim($rawAction));

        if (in_array($action, ['keep', 'mask', 'drop'], true)) {
            return ['type' => $action];
        }

        return null;
    }

    /**
     * Apply a parsed action to a decoded query parameter value.
     *
     * @param array{type:string,count?:int,mask?:string} $action
     */
    private static function applyQueryParamAction(string $value, array $action): string
    {
        $mask = array_key_exists('mask', $action)
            ? (string) $action['mask']
            : (string) config('stellar-ai.query_param_mask', '<removed>');

        if ($action['type'] === 'mask') {
            return $mask;
        }

        if ($action['type'] === 'show_first') {
            $count = max(0, (int) ($action['count'] ?? 0));
            return self::substring($value, 0, $count) . $mask;
        }

        if ($action['type'] === 'show_last') {
            $count = max(0, (int) ($action['count'] ?? 0));

            if ($count === 0) {
                return $mask;
            }

            return $mask . self::substring($value, -$count);
        }

        return $mask;
    }

    /**
     * Case-insensitive glob matching with * and ? wildcards.
     */
    private static function matchesPattern(string $key, string $pattern): bool
    {
        $quoted = preg_quote($pattern, '/');
        $regex = str_replace(['\\*', '\\?'], ['.*', '.'], $quoted);

        return preg_match('/^' . $regex . '$/iu', $key) === 1;
    }

    /**
     * UTF-8 aware substring when mbstring is available, with a safe fallback.
     */
    private static function substring(string $value, int $start, ?int $length = null): string
    {
        if (function_exists('mb_substr')) {
            return $length === null
                ? mb_substr($value, $start, null, 'UTF-8')
                : mb_substr($value, $start, $length, 'UTF-8');
        }

        return $length === null
            ? substr($value, $start)
            : substr($value, $start, $length);
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
