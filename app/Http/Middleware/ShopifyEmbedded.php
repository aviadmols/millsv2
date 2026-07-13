<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the admin panel run INSIDE the Shopify Admin iframe.
 *
 * Two jobs:
 *
 * 1. Allow the framing. Shopify must be permitted as a frame-ancestor, and any
 *    X-Frame-Options must go — it would veto the iframe regardless of CSP.
 *
 * 2. Keep the embed context alive across navigation. Shopify hands us `host`,
 *    `shop` and `embedded` on the first load, and App Bridge needs `host` to
 *    initialise. Filament redirects constantly (login, after-save, resource
 *    routing), and a plain redirect drops the query string — App Bridge then wakes
 *    up outside its iframe context and the app falls out of the Shopify admin. So
 *    every same-origin redirect carries those params forward.
 *
 * Whether Shopify frames us at all is still governed by "Embed app in Shopify
 * admin" in the Partner Dashboard; with it off, Shopify opens the app in a new tab
 * no matter what this middleware does.
 */
class ShopifyEmbedded
{
    /** The params Shopify uses to identify the embed session. */
    private const EMBED_PARAMS = ['host', 'shop', 'embedded', 'id_token', 'locale'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $this->allowFraming($response);
        $this->preserveEmbedContext($request, $response);

        return $response;
    }

    private function allowFraming(Response $response): void
    {
        $shop = (string) config('shopify.shop_domain', '');

        $ancestors = 'https://admin.shopify.com https://*.myshopify.com';
        if ($shop !== '') {
            $ancestors .= " https://{$shop}";
        }

        $directive = "frame-ancestors {$ancestors}";
        $existing = (string) $response->headers->get('Content-Security-Policy', '');

        // Add our directive rather than replacing whatever else is there.
        $policy = $existing === ''
            ? $directive.';'
            : preg_replace('/frame-ancestors[^;]*;?\s*/i', '', $existing).'; '.$directive.';';

        $response->headers->set('Content-Security-Policy', trim($policy, '; ').';');

        // X-Frame-Options would block the iframe outright, whatever the CSP says.
        $response->headers->remove('X-Frame-Options');
    }

    /**
     * Carry Shopify's embed params through redirects, so App Bridge never loses the
     * host it needs and the panel stays inside the Shopify admin.
     */
    private function preserveEmbedContext(Request $request, Response $response): void
    {
        if (! $response instanceof RedirectResponse) {
            return;
        }

        $carry = array_filter(
            $request->only(self::EMBED_PARAMS),
            fn ($value) => $value !== null && $value !== '',
        );

        if ($carry === []) {
            return;
        }

        $target = $response->getTargetUrl();

        // Only rewrite redirects that stay in this app — never rewrite a redirect
        // out to Shopify or to a payment provider.
        $host = parse_url($target, PHP_URL_HOST);
        if ($host !== null && $host !== $request->getHost()) {
            return;
        }

        $parts = parse_url($target);
        parse_str($parts['query'] ?? '', $query);

        // Anything already on the target wins — we only fill in what is missing.
        $merged = $carry + $query;

        $rebuilt = ($parts['path'] ?? '/').'?'.http_build_query($merged)
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');

        $response->setTargetUrl($rebuilt);
    }
}
