<?php

return [
    // App credentials (Partner Dashboard, custom distribution). The offline access
    // token is captured via OAuth and stored encrypted in `shopify_connection`.
    'api_key' => env('SHOPIFY_API_KEY'),
    'api_secret' => env('SHOPIFY_API_SECRET'),

    // Pinned Admin API version — bump deliberately, in this one place.
    'api_version' => env('SHOPIFY_API_VERSION', '2026-07'),

    'app_url' => env('APP_URL'),

    // The single store this app serves (also captured via OAuth). Used for the
    // embedded CSP frame-ancestors allow-list.
    'shop_domain' => env('SHOPIFY_SHOP_DOMAIN'),

    // Embedded admin (opens inside Shopify Admin via App Bridge).
    'embedded' => filter_var(env('SHOPIFY_EMBEDDED', true), FILTER_VALIDATE_BOOL),

    // OAuth scopes requested at install (ARCHITECTURE.md §1b).
    'oauth_scopes' => env(
        'SHOPIFY_SCOPES',
        'read_products,read_orders,write_orders,read_draft_orders,write_draft_orders,read_customers,write_customers'
    ),

    // Webhook verification secret (app secret) + the single receive endpoint.
    'webhook_secret' => env('SHOPIFY_WEBHOOK_SECRET', env('SHOPIFY_API_SECRET')),
    'webhook_address' => rtrim((string) env('APP_URL'), '/').'/shopify/webhooks',
    'webhook_topics' => [
        'orders/paid',
        'orders/create',
        'orders/cancelled',
        'app/uninstalled',
        'products/create',
        'products/update',
        'products/delete',
    ],

    // Sales Channel attribution (ARCHITECTURE.md §1b, D17). source_name MUST equal
    // the channel handle for orders to appear under the app's channel.
    'sales_channel_handle' => env('SHOPIFY_SALES_CHANNEL_HANDLE', 'mills-subscriptions'),
    'order_source_name' => env('SHOPIFY_ORDER_SOURCE_NAME', 'mills-subscriptions'),
    'order_tx_gateway' => 'manual',
    'order_tx_source' => 'external',

    // The single subscription product whose variants are the recurring flavors.
    'subscription_product_id' => env('SHOPIFY_SUBSCRIPTION_PRODUCT_ID', '8499033800792'),

    // Storefront personal-area token (frozen v1 format; OTP mints the same shape).
    'storefront_token_secret' => env('STOREFRONT_TOKEN_SECRET'),
    'storefront_token_max_age' => (int) env('STOREFRONT_TOKEN_MAX_AGE_SECONDS', 86400),
];
