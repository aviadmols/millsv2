<?php

namespace App\Http\Controllers\Storefront;

use App\Modules\MillsSubscriptions\Services\Shopify\CustomerAddressPuller;
use App\Modules\MillsSubscriptions\Services\Shopify\OrderHistoryService;
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
    public function show(Request $request, CustomerAddressPuller $addresses): JsonResponse
    {
        $customer = $this->requireCustomer($request);

        // The one exception to "no live Shopify read": a customer with NO address gets the
        // one Shopify holds, once — after that the local row has it and this is a no-op.
        $addresses->fromShopify($customer);

        return $this->ok(StorefrontPresenter::me($customer));
    }

    /**
     * GET /storefront/me/orders — every order the customer actually placed.
     *
     * The theme's order history rendered from Liquid `customer.orders`, which only exists for
     * a Shopify-logged-in session — an SMS-logged-in customer (or the admin preview) saw
     * "לא ביצעת הזמנות עדיין" forever, whatever they had bought. This is the token-side
     * source: same Shopify read the admin uses, minus the admin-only fields.
     */
    public function orders(Request $request, OrderHistoryService $orders): JsonResponse
    {
        $customer = $this->requireCustomer($request);

        $list = [];

        foreach ($orders->forCustomer($customer, 50) as $order) {
            // Deliberately NOT the whole presenter row: admin_url is an admin link and has no
            // business inside a customer-facing payload.
            $list[] = [
                'id' => $order['id'],
                'name' => $order['name'],
                'created_at' => $order['created_at'],
                'financial_status' => $order['financial_status'],
                'fulfillment_status' => $order['fulfillment_status'],
                'total' => $order['total'],
                'currency' => $order['currency'],
                'line_items' => $order['line_items'],
            ];
        }

        return $this->ok(['orders' => $list]);
    }
}
