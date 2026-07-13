<?php

/*
 * Which browsers may call this app, and on which routes.
 *
 * Laravel's default is `paths => ['api/*']`. That is fatal here: the Shopify theme calls
 * `/storefront/*` (the entire personal area) and the legacy `/shopify/*` and `/order/*`
 * roots (the quiz) — all with `mode: "cors"` — so without this file the browser blocks
 * every one of them and NOTHING the theme does works, no matter how correct the URLs are.
 *
 * Origins are listed explicitly rather than `*`. These routes carry a bearer token; a
 * wildcard would let any site on the internet drive them with a token it managed to get
 * hold of. The cost of naming the origins is that a new storefront domain must be added
 * here — which is the right trade.
 *
 * `supports_credentials` is FALSE on purpose: the theme sends `credentials: "omit"` and
 * authenticates with an Authorization header, not a cookie. Turning it on would also make
 * a wildcard origin illegal, and would invite cookie-based CSRF onto these routes.
 */

return [

    'paths' => [
        'api/*',              // the machine-to-machine surface (incl. the quiz)
        'storefront/*',       // the personal area
        'shopify/subscription/*',
        'shopify/dog/*',      // the legacy NestJS aliases the live theme still calls
        'order',
        'order/*',
    ],

    'allowed_methods' => ['GET', 'POST', 'PATCH', 'PUT', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'CORS_ALLOWED_ORIGINS',
        'https://millsforpets.com,https://www.millsforpets.com,https://millsforpets.myshopify.com',
    ))))),

    // Shopify theme previews are served from *.shopifypreview.com — allow them by pattern
    // so a preview copy can be tested before anything is published.
    'allowed_origins_patterns' => [
        '#^https://[a-z0-9-]+\.shopifypreview\.com$#',
        '#^https://[a-z0-9-]+\.myshopify\.com$#',
    ],

    'allowed_headers' => [
        'Accept',
        'Authorization',          // the storefront bearer token
        'Content-Type',
        'X-API-Secret',
        'X-Requested-With',
        'X-Mills-Dev-Customer-Id',
    ],

    'exposed_headers' => [],

    'max_age' => 3600,           // cache the preflight for an hour

    'supports_credentials' => false,

];
