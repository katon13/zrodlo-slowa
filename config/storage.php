<?php
declare(strict_types=1);

return [
    'driver' => strtolower((string)env('OBJECT_STORAGE_DRIVER', 'local')),
    'local' => [
        'root' => (string)env('LOCAL_STORAGE_ROOT', 'public/uploads'),
        'public_prefix' => (string)env('LOCAL_STORAGE_PUBLIC_PREFIX', '/uploads'),
    ],
    's3' => [
        'endpoint' => (string)env('S3_ENDPOINT', ''),
        'region' => (string)env('S3_REGION', 'us-east-1'),
        'bucket' => (string)env('S3_BUCKET', ''),
        'access_key' => (string)env('S3_ACCESS_KEY', ''),
        'secret_key' => (string)env('S3_SECRET_KEY', ''),
        'path_style' => env_bool('S3_PATH_STYLE', false),
        'reference_prefix' => '/objects',
        'max_attempts' => max(1, (int)env('S3_MAX_ATTEMPTS', 3)),
        'connect_timeout' => max(0.1, (float)env('S3_CONNECT_TIMEOUT', 2.0)),
        'request_timeout' => max(1.0, (float)env('S3_REQUEST_TIMEOUT', 10.0)),
        'max_read_bytes' => max(1_048_576, (int)env('S3_MAX_READ_BYTES', 10_485_760)),
    ],
];
