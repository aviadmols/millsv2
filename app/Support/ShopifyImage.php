<?php

namespace App\Support;

/**
 * Shopify CDN images, sized for the screen.
 *
 * The stored assets are the ORIGINALS — one of them is a 1.07 MB PNG. Rendering a
 * subscription screen with a handful of unsized thumbnails would push tens of megabytes
 * down the wire; a 40-row product table would push tens more. Shopify's CDN resizes on
 * demand, so we always ask for the size we are actually going to display (a 120 px
 * thumbnail of that same file is 35 KB — 31× smaller).
 *
 * The stored URLs already carry a `?v=` cache-busting query, so the size parameter must
 * be appended with `&`. Naively concatenating `?width=120` produces a malformed URL with
 * two query strings and Shopify serves the original anyway.
 */
final class ShopifyImage
{
    /** Rendered thumbnails are 40-48px; ask for 2x so they stay crisp on retina. */
    public const THUMB_WIDTH = 120;

    public static function thumb(?string $url, int $width = self::THUMB_WIDTH): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        // Only Shopify's CDN understands the resize parameter; leave anything else alone.
        if (! str_contains($url, 'cdn.shopify.com')) {
            return $url;
        }

        if (preg_match('/[?&]width=\d+/', $url) === 1) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').'width='.$width;
    }
}
