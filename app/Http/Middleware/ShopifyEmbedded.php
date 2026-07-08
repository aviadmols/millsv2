<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the admin panel render INSIDE the Shopify Admin iframe (embedded app).
 * Sets a `frame-ancestors` CSP allowing Shopify to frame us, and drops any
 * X-Frame-Options that would block it. App Bridge itself is injected via the
 * Filament head render hook (AdminPanelProvider).
 */
class ShopifyEmbedded
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $shop = (string) config('shopify.shop_domain', '');
        $ancestors = 'https://admin.shopify.com https://*.myshopify.com';
        if ($shop !== '') {
            $ancestors .= " https://{$shop}";
        }

        $response->headers->set('Content-Security-Policy', "frame-ancestors {$ancestors};");
        $response->headers->remove('X-Frame-Options');

        return $response;
    }
}
