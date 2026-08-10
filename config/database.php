<?php
return [
    'default' => [
        'driver' => env('DB_DRIVER', 'pgsql'),
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', '5432'),
        'database' => env('DB_NAME', 'zrodlo_slowa'),
        'username' => env('DB_USER', 'postgres'),
        'password' => env('DB_PASS', ''),
        'charset' => env('DB_CHARSET', 'utf8'),
        'schema' => env('DB_SCHEMA', 'public'),
        'sslmode' => env('DB_SSLMODE', 'prefer'),
        'application_name' => env('DB_APPLICATION_NAME', 'zrodlo-slowa'),
        'allow_create_database' => env_bool('DB_ALLOW_CREATE_DATABASE', false),
        'allow_create_schema' => env_bool('DB_ALLOW_CREATE_SCHEMA', false),
    ],
];
