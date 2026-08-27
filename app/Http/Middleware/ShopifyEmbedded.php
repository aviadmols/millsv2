<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    /**
     * The params Shopify uses to identify the embed session.
     *
     * `locale` is deliberately NOT here. Shopify appends the staff member's Shopify-admin
     * language, the language-switch package prefers a `locale` query param over
     * config('app.locale'), and carrying it through every redirect made it permanent —
     * so opening the app from an English Shopify admin rendered this Hebrew-first system
     * in English. App Bridge does not need it; the panel's language is ours to decide.
     */
    private const EMBED_PARAMS = ['host', 'shop', 'embedded', 'id_token'];

    /** Request attribute carrying the configured language past the language switcher. */
    public const CONFIGURED_LOCALE = 'mills.configured_locale';

    public function handle(Request $request, Closure $next): Response
    {
        $this->ignoreShopifyLocale($request);

        $response = $next($request);

        $this->allowFraming($response);
        $this->preserveEmbedContext($request, $response);

        return $response;
    }

    /**
     * Drop the `locale` Shopify appends to an embedded load.
     *
     * It carries the staff member's SHOPIFY-admin language, and the language-switch
     * package prefers a `locale` query param over everything else — so opening this
     * Hebrew-first system from an English Shopify admin rendered every screen in
     * English, including forms whose Hebrew translations were sitting right there.
     *
     * Only on an embedded request, and only Shopify's copy: the topbar switcher sends
     * the same parameter on its own (non-embedded) redirect and must keep working.
     */
    private function ignoreShopifyLocale(Request $request): void
    {
        $isEmbedded = $request->query->has('embedded') || $request->query->has('host');

        if (! $isEmbedded) {
            return;
        }

        $request->query->remove('locale');

        /*
         * Hand the CONFIGURED language forward, before anything can move it.
         *
         * app()->setLocale() writes config('app.locale') — so once the language switcher
         * has run, the config no longer says what the system was configured to speak, it
         * says what that request decided. This middleware runs first, so this is the last
         * moment the original value can be read; ShopifyEmbeddedAuthenticate restores it
         * after the switcher, where it gets the final word.
         */
        $request->attributes->set(self::CONFIGURED_LOCALE, (string) config('app.locale'));
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
