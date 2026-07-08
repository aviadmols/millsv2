<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use App\Support\StorefrontToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the personal area (SYSTEM-MAP §3.3). Reads the Bearer token,
 * verifies the frozen HMAC, resolves the local Customer by shopify_customer_id
 * (the DB is the source of truth), and attaches it as the request `customer`
 * attribute. Errors use the frozen envelope.
 */
class VerifyStorefrontToken
{
    // === CONSTANTS ===
    public const REQUEST_ATTR_CUSTOMER = 'customer';

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('shopify.storefront_token_secret', '');
        if ($secret === '') {
            // Fail closed in production; allow a dev bypass header locally.
            if (app()->isProduction()) {
                return $this->deny('not_configured', 503);
            }
            $devId = $request->header('X-Mills-Dev-Customer-Id');
            if ($devId && ($c = Customer::query()->where('shopify_customer_id', $devId)->first())) {
                $request->attributes->set(self::REQUEST_ATTR_CUSTOMER, $c);

                return $next($request);
            }
        }

        $token = $request->bearerToken();
        if (! $token) {
            return $this->deny('missing_token');
        }

        $subject = StorefrontToken::verify($token);
        if ($subject === null) {
            return $this->deny('invalid_token');
        }

        $customer = Customer::query()->where('shopify_customer_id', $subject)->first();
        if ($customer === null) {
            return $this->deny('customer_not_found');
        }

        $request->attributes->set(self::REQUEST_ATTR_CUSTOMER, $customer);

        return $next($request);
    }

    private function deny(string $reason, int $status = 401): Response
    {
        return response()->json([
            'ok' => false,
            'error' => 'unauthenticated',
            'reason' => $reason,
        ], $status);
    }
}
