<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Dog;
use App\Models\Subscription;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Shared id handling for the machine-to-machine API (SYSTEM-MAP §3.1/§3.2).
 * Every {id} accepts EITHER the numeric v2 id OR the legacy Shopify GID that the
 * theme still holds from v1 — both resolve to the same row.
 */
abstract class AbstractApiController extends Controller
{
    protected function resolveSubscription(string $id): Subscription
    {
        $subscription = Subscription::query()
            ->where('id', ctype_digit($id) ? (int) $id : 0)
            ->orWhere('legacy_shopify_gid', $id)
            ->first();

        return $subscription ?? $this->notFound('Subscription not found');
    }

    protected function resolveDog(string $id): Dog
    {
        $dog = Dog::query()
            ->where('id', ctype_digit($id) ? (int) $id : 0)
            ->orWhere('legacy_shopify_gid', $id)
            ->first();

        return $dog ?? $this->notFound('Dog not found');
    }

    protected function resolveCustomer(string $id): Customer
    {
        $customer = Customer::query()
            ->where('id', ctype_digit($id) ? (int) $id : 0)
            ->orWhere('shopify_customer_id', $id)
            ->orWhere('legacy_shopify_gid', $id)
            ->first();

        return $customer ?? $this->notFound('Customer not found');
    }

    /**
     * Collect ids from the modern single field or the legacy plural alias
     * (dogId | dogIds[], variantId | id | variantIds[]).
     *
     * @param  array<string, mixed>  $body
     * @param  list<string>  $keys
     * @return list<string>
     */
    protected function idList(array $body, array $keys): array
    {
        $ids = [];

        foreach ($keys as $key) {
            $value = $body[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            foreach ((array) $value as $item) {
                if (is_scalar($item) && (string) $item !== '') {
                    $ids[] = (string) $item;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return never */
    private function notFound(string $message)
    {
        throw new HttpResponseException(response()->json(['message' => $message], 404));
    }
}
