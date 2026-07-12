<?php

namespace App\Http\Controllers\Storefront;

use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Services\Shopify\CustomerAddressPusher;
use App\Modules\MillsSubscriptions\Support\StorefrontPresenter;
use App\Modules\MillsSubscriptions\Support\Timeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PATCH /storefront/me/address — the customer's shipping address.
 *
 * The local DB is written FIRST (it is the source of truth), then the address is
 * pushed to the Shopify customer so shipping labels match. A failed push is
 * logged and surfaced as `shopify_synced:false`, but never fails the request.
 */
class StorefrontAddressController extends AbstractStorefrontController
{
    public function update(Request $request, CustomerAddressPusher $pusher): JsonResponse
    {
        $customer = $this->requireCustomer($request);

        $data = $request->validate([
            'firstName' => ['sometimes', 'nullable', 'string', 'max:100'],
            'lastName' => ['sometimes', 'nullable', 'string', 'max:100'],
            'address1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'province' => ['sometimes', 'nullable', 'string', 'max:100'],
            'country' => ['sometimes', 'nullable', 'string', 'max:100'],
            'zip' => ['sometimes', 'nullable', 'string', 'max:32'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        if ($data === []) {
            return $this->fail('no_fields', 'לא נשלחו שדות לעדכון.', 422);
        }

        $map = [
            'firstName' => 'first_name',
            'lastName' => 'last_name',
            'address1' => 'address1',
            'address2' => 'address2',
            'city' => 'city',
            'province' => 'province',
            'country' => 'country',
            'zip' => 'zip',
            'phone' => 'phone',
        ];

        foreach ($data as $key => $value) {
            $customer->{$map[$key]} = $value;
        }
        $customer->save();

        SystemLog::info('storefront', 'address updated by customer', [
            'fields' => array_keys($data),
        ], ['customer_id' => $customer->id]);

        Timeline::record(Timeline::KIND_ADDRESS_UPDATED, ['fields' => array_keys($data)], null, $customer->id, Timeline::ACTOR_CUSTOMER);

        $synced = $pusher->push($customer);

        return $this->ok([
            'address' => StorefrontPresenter::address($customer->fresh()),
            'shopify_synced' => $synced,
        ]);
    }
}
