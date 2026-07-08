<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fail-closed raw-body HMAC verification for Shopify webhooks (ARCHITECTURE.md
 * §1b). Empty secret → 503 in production. Invalid/absent HMAC → 401. The raw body
 * must be read before any JSON parsing mutates it.
 */
class VerifyShopifyWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('shopify.webhook_secret', '');
        if ($secret === '') {
            return response()->json(['error' => 'webhook_not_configured'], app()->isProduction() ? 503 : 401);
        }

        $hmac = (string) $request->header('X-Shopify-Hmac-Sha256', '');
        if ($hmac === '') {
            return response()->json(['error' => 'missing_hmac'], 401);
        }

        $calculated = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        if (! hash_equals($calculated, $hmac)) {
            return response()->json(['error' => 'invalid_hmac'], 401);
        }

        return $next($request);
    }
}
