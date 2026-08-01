<?php
$stripeEnabled = in_array(strtolower((string)env('STRIPE_ENABLED', 'false')), ['1', 'true', 'yes', 'on'], true);

return [
    'points_rate' => [
        'points' => 10,
        'currency_minor' => 100,
        'currency' => 'PLN',
    ],
    'providers' => [
        'manual' => true,
        'przelewy24' => false,
        'stripe' => $stripeEnabled,
    ],
    'stripe' => [
        'enabled' => $stripeEnabled,
        'mode' => (string)env('STRIPE_MODE', 'test'),
        'secret_key' => (string)env('STRIPE_SECRET_KEY', ''),
        'public_key' => (string)env('STRIPE_PUBLIC_KEY', ''),
        'webhook_secret' => (string)env('STRIPE_WEBHOOK_SECRET', ''),
        'currency' => strtolower((string)env('STRIPE_CURRENCY', 'pln')),
        'payment_methods' => (string)env('STRIPE_PAYMENT_METHODS', 'card,p24'),
        'success_url' => (string)env('STRIPE_CHECKOUT_SUCCESS_URL', 'http://localhost:8080/wallet/topup/success?session_id={CHECKOUT_SESSION_ID}'),
        'cancel_url' => (string)env('STRIPE_CHECKOUT_CANCEL_URL', 'http://localhost:8080/wallet/topup/cancel'),
        'webhook_url' => (string)env('STRIPE_WEBHOOK_URL', 'http://localhost:8080/stripe/webhook'),
    ],
    'wallet_transfer' => [
        'talent_to_pln' => [
            'enabled' => true,
            'fee_percent' => 5,
            'min_talent' => 100,
            'max_daily_talent' => 5000,
            'auto_approve_below_pln_minor' => 5000,
        ],
        'pln_to_talent' => [
            'enabled' => true,
            'rate' => 10,
        ],
    ],
    'wallet' => [
        'tt_per_pln' => 10,
    ],
];
