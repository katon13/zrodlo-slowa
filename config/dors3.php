<?php
declare(strict_types=1);

$mode = strtolower(trim((string)env('DORS3_MODE', 'prepare')));

return [
    'mode' => in_array($mode, ['prepare', 'test', 'required'], true) ? $mode : 'prepare',
    'mobile' => [
        'enabled' => env_bool('DORS3_MOBILE_ENABLED', false),
        'mode' => in_array(strtolower(trim((string)env('DORS3_MOBILE_MODE', 'disabled'))), ['disabled', 'test', 'required'], true)
            ? strtolower(trim((string)env('DORS3_MOBILE_MODE', 'disabled')))
            : 'disabled',
        'admin_app_enabled' => env_bool('DORS3_ADMIN_APP_ENABLED', false),
        'author_app_enabled' => env_bool('DORS3_AUTHOR_APP_ENABLED', false),
        'article_submit_approval' => env_bool('DORS3_ARTICLE_SUBMIT_APPROVAL', false),
        'article_publish_approval' => env_bool('DORS3_ARTICLE_PUBLISH_APPROVAL', false),
        'payout_approval' => env_bool('DORS3_PAYOUT_APPROVAL', false),
        'admin_critical_approval' => env_bool('DORS3_ADMIN_CRITICAL_APPROVAL', false),
        'admin_app_link_base_url' => rtrim(trim((string)env(
            'DORS3_ADMIN_APP_LINK_BASE_URL',
            'https://admin-3dors.przyklad-domeny.pl/3dors/approve',
        )), '/'),
        'author_app_link_base_url' => rtrim(trim((string)env(
            'DORS3_AUTHOR_APP_LINK_BASE_URL',
            'https://author-3dors.przyklad-domeny.pl/3dors/approve',
        )), '/'),
        'request_ttl_seconds' => max(30, min(90, (int)env('DORS3_MOBILE_REQUEST_TTL_SECONDS', 60))),
        'enrollment_ttl_seconds' => max(120, min(900, (int)env('DORS3_MOBILE_ENROLLMENT_TTL_SECONDS', 300))),
        'api_token_ttl_seconds' => max(86400, min(7776000, (int)env('DORS3_MOBILE_API_TOKEN_TTL_SECONDS', 2592000))),
        'max_pending_per_user' => max(1, min(10, (int)env('DORS3_MOBILE_MAX_PENDING_PER_USER', 3))),
    ],
    'fido2_enabled' => env_bool('DORS3_FIDO2_ENABLED', false),
    'fido2_required' => env_bool('DORS3_FIDO2_REQUIRED', false),
    'critical_step_up' => strtolower(trim((string)env('DORS3_CRITICAL_STEP_UP', 'password'))),
    'physical_approval' => strtolower(trim((string)env('DORS3_PHYSICAL_APPROVAL', 'disabled'))),
    'admin_idle_timeout_seconds' => max(300, min(3600, (int)env('DORS3_ADMIN_IDLE_TIMEOUT_SECONDS', 900))),
    'admin_session_max_seconds' => max(3600, min(86400, (int)env('DORS3_ADMIN_SESSION_MAX_SECONDS', 28800))),
    'step_up_ttl_seconds' => max(60, min(900, (int)env('WEBAUTHN_STEP_UP_TTL_SECONDS', 300))),
    'webauthn' => [
        'enabled' => env_bool('WEBAUTHN_ENABLED', false),
        'rp_id' => trim((string)env('WEBAUTHN_RP_ID', 'localhost')),
        'rp_name' => trim((string)env('WEBAUTHN_RP_NAME', 'Źródło Słowa — 3DORS')),
        'origin' => rtrim(trim((string)env('WEBAUTHN_ORIGIN', 'http://localhost:8080')), '/'),
        'user_verification' => strtolower(trim((string)env('WEBAUTHN_USER_VERIFICATION', 'required'))),
        'challenge_ttl_seconds' => max(60, min(900, (int)env('WEBAUTHN_CHALLENGE_TTL_SECONDS', 300))),
    ],
];
