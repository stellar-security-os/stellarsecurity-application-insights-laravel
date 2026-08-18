<?php

declare(strict_types=1);

$config = [];
$environment = [];

if (! function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        global $config;

        return $config[$key] ?? $default;
    }
}

if (! function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        global $environment;

        return array_key_exists($key, $environment)
            ? $environment[$key]
            : $default;
    }
}

require __DIR__ . '/../src/Helpers/TelemetrySanitizer.php';

use StellarSecurity\ApplicationInsightsLaravel\Helpers\TelemetrySanitizer;

function assertSameValue(string $name, mixed $expected, mixed $actual): void
{
    if ($actual !== $expected) {
        fwrite(
            STDERR,
            "FAIL: {$name}\nExpected: " . var_export($expected, true) . "\nActual:   " . var_export($actual, true) . "\n"
        );
        exit(1);
    }

    echo "PASS: {$name}\n";
}

function assertQuerySanitization(
    string $name,
    array $settings,
    string $input,
    string $expected
): void {
    global $config;

    $config = $settings;
    $actual = TelemetrySanitizer::sanitizeQueryString($input);

    assertSameValue($name, $expected, $actual);
}

$defaults = [
    'stellar-ai.query_param_rules' => [],
    'stellar-ai.query_param_use_builtin_sensitive_rules' => true,
    'stellar-ai.query_param_default_action' => 'keep',
    'stellar-ai.query_param_mask' => '<removed>',
];

assertQuerySanitization(
    'Backward-compatible defaults',
    $defaults,
    'status=pending&token=secret-value&page=2',
    'status=pending&token=%3Cremoved%3E&page=2'
);

assertQuerySanitization(
    'JSON object rules, wildcards, partial masking, drop, and precedence',
    array_merge($defaults, [
        'stellar-ai.query_param_rules' => [
            'token' => ['action' => 'show_last', 'characters' => 4],
            'customer' => ['action' => 'show_first', 'characters' => 3],
            'utm_*' => ['action' => 'keep'],
            'debug' => ['action' => 'drop'],
            '*secret*' => ['action' => 'mask'],
            'email' => ['action' => 'keep'],
        ],
    ]),
    'token=abcdef1234&customer=ABCDEFG&utm_source=test&debug=1&my_secret=hello&email=a%40b.com&password=pass',
    'token=%3Cremoved%3E1234&customer=ABC%3Cremoved%3E&utm_source=test&my_secret=%3Cremoved%3E&email=a%40b.com&password=%3Cremoved%3E'
);

assertQuerySanitization(
    'Case-insensitive wildcard matching',
    array_merge($defaults, [
        'stellar-ai.query_param_rules' => [
            '*KEY' => ['action' => 'show_first', 'characters' => 2],
        ],
    ]),
    'API_KEY=abcdef',
    'API_KEY=ab%3Cremoved%3E'
);

assertQuerySanitization(
    'Built-in rules can be disabled',
    array_merge($defaults, [
        'stellar-ai.query_param_use_builtin_sensitive_rules' => false,
    ]),
    'token=visible&email=a%40b.com',
    'token=visible&email=a%40b.com'
);

assertQuerySanitization(
    'Default action can mask unmatched parameters',
    array_merge($defaults, [
        'stellar-ai.query_param_use_builtin_sensitive_rules' => false,
        'stellar-ai.query_param_default_action' => 'mask',
    ]),
    'foo=bar&page=2',
    'foo=%3Cremoved%3E&page=%3Cremoved%3E'
);

assertQuerySanitization(
    'Invalid matched structured rule fails closed',
    array_merge($defaults, [
        'stellar-ai.query_param_rules' => [
            'reference' => ['action' => 'show_last', 'characters' => 'nope'],
        ],
    ]),
    'reference=abcdef',
    'reference=%3Cremoved%3E'
);

assertQuerySanitization(
    'Custom global mask marker',
    array_merge($defaults, [
        'stellar-ai.query_param_rules' => [
            'id' => ['action' => 'show_last', 'characters' => 3],
        ],
        'stellar-ai.query_param_mask' => '***',
    ]),
    'id=123456',
    'id=%2A%2A%2A456'
);

assertQuerySanitization(
    'Per-rule mask marker overrides global mask',
    array_merge($defaults, [
        'stellar-ai.query_param_rules' => [
            'phone' => [
                'action' => 'show_last',
                'characters' => 4,
                'mask' => '[hidden]',
            ],
        ],
        'stellar-ai.query_param_mask' => '***',
    ]),
    'phone=41791234567',
    'phone=%5Bhidden%5D4567'
);

assertQuerySanitization(
    'JSON string rules are accepted by sanitizer integrations',
    array_merge($defaults, [
        'stellar-ai.query_param_rules' => '{"session_*":{"action":"mask"},"page":{"action":"keep"}}',
    ]),
    'session_id=abc&page=2',
    'session_id=%3Cremoved%3E&page=2'
);

assertQuerySanitization(
    'Invalid JSON fails closed for all query parameters',
    array_merge($defaults, [
        'stellar-ai.query_param_rules' => '{invalid-json',
    ]),
    'page=2&status=ok',
    'page=%3Cremoved%3E&status=%3Cremoved%3E'
);

assertQuerySanitization(
    'Ordered JSON list supports explicit wildcard precedence',
    array_merge($defaults, [
        'stellar-ai.query_param_rules' => [
            [
                'parameter' => 'utm_secret_*',
                'action' => 'mask',
            ],
            [
                'parameter' => 'utm_*',
                'action' => 'keep',
            ],
        ],
    ]),
    'utm_secret_code=abc&utm_source=google',
    'utm_secret_code=%3Cremoved%3E&utm_source=google'
);

$environment = [
    'STELLAR_AI_QUERY_PARAM_RULES' => '{"token":{"action":"mask"},"customer_id":{"action":"show_last","characters":4}}',
];
$loadedConfig = require __DIR__ . '/../config/stellar-ai.php';

assertSameValue(
    'Package config decodes JSON from environment',
    [
        'token' => ['action' => 'mask'],
        'customer_id' => ['action' => 'show_last', 'characters' => 4],
    ],
    $loadedConfig['query_param_rules']
);

$environment = [
    'STELLAR_AI_QUERY_PARAM_RULES' => '{invalid-json',
];
$loadedConfig = require __DIR__ . '/../config/stellar-ai.php';

assertSameValue(
    'Package config preserves invalid JSON as fail-closed null sentinel',
    null,
    $loadedConfig['query_param_rules']
);

echo "All query parameter rule smoke tests passed.\n";
