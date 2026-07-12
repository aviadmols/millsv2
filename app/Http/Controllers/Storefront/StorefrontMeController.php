<?php

namespace App\Http\Controllers\Storefront;

use App\Modules\MillsSubscriptions\Support\StorefrontPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /storefront/me — the personal-area dashboard (SYSTEM-MAP §3.3).
 *
 * Returns the frozen payload the Shopify theme already parses:
 * {ok, data:{customer, subscriptions[{…, dogs, subscription_products}], dogs[], flags{}}}
 * built entirely from the local DB (the v2 source of truth — no live Shopify read).
 */
class StorefrontMeController extends AbstractStorefrontController
{
    public function show(Request $request): JsonResponse
    {
        $customer = $this->requireCustomer($request);

        return $this->ok(StorefrontPresenter::me($customer));
    }
}
