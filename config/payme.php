<?php

return [
    // Server-side charge API (generate-sale / get-transactions / get-buyer-key).
    'api_url' => env('PAYME_API_URL'),
    'seller_id' => env('PAYME_SELLER_ID'),

    // PayMe Hosted Fields SDK (browser card-update UI; card data never hits us).
    'hosted_fields_api_key' => env('PAYME_HOSTED_FIELDS_API_KEY'),
    'hosted_fields_test_mode' => filter_var(env('PAYME_HOSTED_FIELDS_TEST_MODE', false), FILTER_VALIDATE_BOOL),

    // Session TTL for the self-service card-update flow (minutes).
    'card_update_session_ttl_minutes' => (int) env('PAYME_SESSION_TTL_MINUTES', 15),
];
