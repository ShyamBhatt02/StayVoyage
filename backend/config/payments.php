<?php

return [
    // sandbox | razorpay | stripe — the admin setting `payments.default_gateway` overrides this at runtime.
    'default' => env('PAYMENT_DEFAULT_GATEWAY', 'sandbox'),

    // The built-in simulator must never be reachable in production unless explicitly enabled.
    'sandbox' => [
        'enabled' => env('PAYMENT_SANDBOX_ENABLED', env('APP_ENV') !== 'production'),
        // Signing secret for simulated webhooks, derived from APP_KEY so there is nothing to configure.
        'secret' => hash_hmac('sha256', 'stayvoyage-sandbox-gateway', (string) env('APP_KEY')),
    ],

    'razorpay' => [
        'key_id' => env('RAZORPAY_KEY_ID'),
        'key_secret' => env('RAZORPAY_KEY_SECRET'),
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'frontend_url' => env('FRONTEND_URL', env('APP_URL')),
];
