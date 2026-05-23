<?php

return [
    'invitations' => [
        'expires_after_days' => (int) env('FOODPECKER_INVITATIONS_EXPIRES_DAYS', 14),
    ],

    'platform_fee_percent' => (float) env('FOODPECKER_PLATFORM_FEE_PERCENT', 1.0),

    'demo' => [
        'owner_email' => env('FOODPECKER_DEMO_OWNER_EMAIL', 'marie@foodpecker.test'),
        'owner_password' => env('FOODPECKER_DEMO_OWNER_PASSWORD', 'password'),
    ],
];
