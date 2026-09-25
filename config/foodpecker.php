<?php

return [
    'invitations' => [
        'expires_after_days' => (int) env('FOODPECKER_INVITATIONS_EXPIRES_DAYS', 14),
    ],

    'platform_fee_percent' => (float) env('FOODPECKER_PLATFORM_FEE_PERCENT', 1.0),

    /*
     * Reverse proxies in front of the app (e.g. nginx on Plesk, a load
     * balancer or `herd share`). Comma separated IPs or "*". Needed so
     * generated links — especially the signed invitation links — keep
     * the https scheme and the public host.
     */
    'trusted_proxies' => env('TRUSTED_PROXIES'),

    'demo' => [
        'owner_email' => env('FOODPECKER_DEMO_OWNER_EMAIL', 'marie@foodpecker.test'),
        'owner_password' => env('FOODPECKER_DEMO_OWNER_PASSWORD', 'password'),
    ],
];
