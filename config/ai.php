<?php
return [
    'enabled' => filter_var(env('AI_ENABLED', env('OPENAI_ENABLED', false)), FILTER_VALIDATE_BOOLEAN),
    'default_provider' => env('AI_DEFAULT_PROVIDER', 'openai'),
    'openai' => [
        'api_key' => env('OPENAI_API_KEY', ''),
        'model' => env('OPENAI_MODEL', 'gpt-5.5'),
        'premium_model' => env('OPENAI_PREMIUM_MODEL', env('OPENAI_MODEL', 'gpt-5.5')),
        'temperature' => (float)env('OPENAI_TEMPERATURE', '0.2'),
    ],
    'translation' => [
        'enabled' => filter_var(
            env('OPENAI_TRANSLATION_ENABLED', env('OPENAI_ENABLED', false)),
            FILTER_VALIDATE_BOOLEAN
        ),
        'model' => env('OPENAI_TRANSLATION_MODEL', env('OPENAI_MODEL', 'gpt-5.5')),
        'premium_model' => env('OPENAI_TRANSLATION_PREMIUM_MODEL', env('OPENAI_PREMIUM_MODEL', env('OPENAI_MODEL', 'gpt-5.5'))),
        'max_chars_per_job' => (int)env('OPENAI_TRANSLATION_MAX_CHARS', 60000),
        'daily_jobs_limit' => (int)env('OPENAI_TRANSLATION_DAILY_JOBS_LIMIT', 20),
        'monthly_budget_minor' => (int)env('OPENAI_TRANSLATION_MONTHLY_BUDGET_MINOR', 5000),
        'require_editor_review' => true,
    ],
    'storage' => [
        'source_of_truth' => 'database',
        'raw_json_policy' => 'audit_only',
    ],
];
