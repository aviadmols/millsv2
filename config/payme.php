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

    /*
     * The card-update verification charge, in AGOROT.
     *
     * PayMe will not tokenise a card for nothing, so capturing a reusable buyer_key costs
     * the customer a real, small charge. It lives here — one named, reviewable number —
     * rather than as a default inside the HTTP client, where it sat at 100 agorot (₪1) and
     * quietly billed every customer who updated a card. v1 charged ₪0.10.
     *
     * The end state is 0: ask PayMe to enable zero-amount tokenisation, set this to 0, and
     * the charge (and its ledger row) disappears without touching any other code.
     */
    'card_update_verification_agorot' => (int) env('PAYME_CARD_UPDATE_VERIFICATION_AGOROT', 10),
];
