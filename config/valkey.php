<?php
declare(strict_types=1);

$environment = strtolower(trim((string)env('APP_ENV', 'local')));

return [
    'host' => (string)env('VALKEY_HOST', '127.0.0.1'),
    'port' => max(1, (int)env('VALKEY_PORT', 6379)),
    'password' => (string)env('VALKEY_PASSWORD', ''),
    'database' => max(0, (int)env('VALKEY_DATABASE', 0)),
    'tls' => env_bool('VALKEY_TLS', false),
    'prefix' => (string)env('VALKEY_PREFIX', 'zrodlo-slowa:' . $environment),
    'persistent_id' => (string)env('VALKEY_PERSISTENT_ID', 'zrodlo-slowa-' . $environment),
    'connect_timeout' => max(0.05, (float)env('VALKEY_CONNECT_TIMEOUT', 0.5)),
    'read_timeout' => max(0.05, (float)env('VALKEY_READ_TIMEOUT', 0.5)),
    'session_driver' => strtolower(trim((string)env('SESSION_DRIVER', 'file'))),
    'session_ttl_seconds' => max(300, (int)env('SESSION_TTL_SECONDS', 86400)),
    'cache_driver' => strtolower(trim((string)env('CACHE_DRIVER', 'file'))),
    'rate_limit_driver' => strtolower(trim((string)env('RATE_LIMIT_DRIVER', 'database'))),
    'lock_driver' => strtolower(trim((string)env('LOCK_DRIVER', 'none'))),
    'queue_signal_driver' => strtolower(trim((string)env('QUEUE_SIGNAL_DRIVER', 'none'))),
];
