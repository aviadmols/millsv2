<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Middleware\VerifyStorefrontToken;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /storefront/me — the personal-area dashboard.
 *
 * PHASE-1 PLACEHOLDER: returns the authenticated customer envelope only. The full
 * frozen payload (subscriptions[], dogs[], subscription_products, flags) is built
 * in Phase 4 (endpoint parity) against the DB read model. The envelope shape
 * ({ok, data:{customer, subscriptions, dogs, flags}}) already matches SYSTEM-MAP
 * §3.3 so the theme contract is stable.
 */
class StorefrontMeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->attributes->get(VerifyStorefrontToken::REQUEST_ATTR_CUSTOMER);

        return response()->json([
            'ok' => true,
            'data' => [
                'customer' => [
                    'id' => $customer->id,
                    'numeric_id' => $customer->shopify_customer_id,
                    'email' => $customer->email,
                    'display_name' => $customer->fullName(),
                ],
                'subscriptions' => [], // TODO Phase 4: full frozen payload
                'dogs' => [],
                'flags' => [
                    'is_empty' => $customer->subscriptions()->count() === 0,
                ],
            ],
        ]);
    }
}
