<?php
$environment = (string)env('APP_ENV', 'local');
$url = (string)env('APP_URL', 'http://localhost:8080');

return [
    'name' => env('APP_NAME', 'ŹRÓDŁO SŁOWA'),
    'env' => $environment,
    'debug' => env_bool('APP_DEBUG', false),
    'url' => $url,
    'business_timezone' => env('BUSINESS_TIMEZONE', 'Europe/Warsaw'),
    'session_name' => env('SESSION_NAME', 'zrodlo_slowa_session'),
    'session' => [
        'lifetime' => max(0, (int)env('SESSION_LIFETIME', 0)),
        'path' => '/',
        'domain' => (string)env('SESSION_DOMAIN', ''),
        'secure' => env_bool('SESSION_SECURE', $environment === 'production' || str_starts_with(strtolower($url), 'https://')),
        'httponly' => true,
        'samesite' => (string)env('SESSION_SAME_SITE', 'Lax'),
    ],
];
