<?php

return [
    // No default on purpose: always set this explicitly via env for the
    // environment you are targeting (local/staging/production).
    'base_url' => env('GATEWAY_MAILER_BASE_URL'),

    'client_id' => env('GATEWAY_MAILER_CLIENT_ID'),

    'client_secret' => env('GATEWAY_MAILER_CLIENT_SECRET'),

    'cache' => [
        'store' => env('GATEWAY_MAILER_CACHE_STORE'),
        'key' => 'gateway-mailer.token',
    ],
];
