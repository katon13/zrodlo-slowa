<?php

return [
    'google' => [
        'enabled' => env_bool('GOOGLE_LOGIN_ENABLED', false),
        'client_id' => env('GOOGLE_CLIENT_ID', ''),
        'client_secret' => env('GOOGLE_CLIENT_SECRET', ''),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI', ''),
        'scopes' => 'openid email profile',
    ],
    'apple' => [
        'enabled' => env_bool('APPLE_LOGIN_ENABLED', false),
        'client_id' => env('APPLE_CLIENT_ID', ''),
        'team_id' => env('APPLE_TEAM_ID', ''),
        'key_id' => env('APPLE_KEY_ID', ''),
        'private_key_path' => env('APPLE_PRIVATE_KEY_PATH', ''),
        'redirect_uri' => env('APPLE_REDIRECT_URI', ''),
        'scopes' => 'name email',
    ],
];
