<?php
declare(strict_types=1);

$mode = strtolower(trim((string)env('DORS3_MODE', 'prepare')));

return [
    'mode' => in_array($mode, ['prepare', 'test', 'required'], true) ? $mode : 'prepare',
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
