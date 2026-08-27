<?php

namespace App\Modules\MillsSubscriptions\Support;

use App\Models\Customer;

/**
 * THE mapping from a Shopify customer payload to our columns.
 *
 * There is one, and there is only ever one. Two customer webhooks and an admin importer all
 * hydrate the same row from the same Shopify shape, and three hand-written copies of that
 * mapping is three places for a field to be forgotten — the kind of drift where a customer
 * imported by staff quietly has no phone number, and nobody notices until an SMS fails to
 * send.
 *
 * The shape is Shopify's REST customer (snake_case, `default_address`), because that is what
 * the webhooks already deliver. ShopifyCustomerService flattens its GraphQL results into the
 * same shape for exactly this reason.
 */
class CustomerMapper
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function attributes(array $payload): array
    {
        $address = (array) ($payload['default_address'] ?? []);

        // array_filter drops nulls: a webhook that omits a field must not blank a value we
        // already hold. Shopify sends partial payloads.
        return array_filter([
            'email' => $payload['email'] ?? null,
            'first_name' => $payload['first_name'] ?? ($address['first_name'] ?? null),
            'last_name' => $payload['last_name'] ?? ($address['last_name'] ?? null),
            'phone' => $payload['phone'] ?? ($address['phone'] ?? null),
            'address1' => $address['address1'] ?? null,
            'address2' => $address['address2'] ?? null,
            'city' => $address['city'] ?? null,
            'province' => $address['province'] ?? null,
            'country' => $address['country'] ?? null,
            'zip' => $address['zip'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Upsert on the Shopify id. Returns null when the payload carries no id — there is nothing
     * to key on, and inventing a customer row from an anonymous payload is worse than skipping.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $extra
     */
    public static function upsert(array $payload, array $extra = []): ?Customer
    {
        $shopifyId = (string) ($payload['id'] ?? '');

        if ($shopifyId === '') {
            return null;
        }

        $attributes = array_merge(self::attributes($payload), $extra);

        $existing = Customer::query()->where('shopify_customer_id', $shopifyId)->first();

        if ($existing === null) {
            /*
             * Somebody already here under this EMAIL, with no Shopify id yet, is the same
             * person — not a new one. Both columns are unique, so creating a second row
             * does not merely duplicate them: it throws, and the import counts a customer
             * it should have linked as a failure. Claim the existing row instead.
             *
             * PaidOrderIngestor::resolveCustomer already reasons exactly this way about a
             * checkout; a bulk import over a list of 1,172 addresses meets it far more often.
             */
            $email = trim((string) ($attributes['email'] ?? ''));

            if ($email !== '') {
                $existing = Customer::query()->where('email', $email)->first();
            }
        }

        if ($existing !== null) {
            // The id is the LINK — an email-matched customer without it would be
            // "found" again as a stranger on every future import.
            $existing->fill($attributes + ['shopify_customer_id' => $shopifyId])->save();

            return $existing;
        }

        return Customer::query()->create($attributes + ['shopify_customer_id' => $shopifyId]);
    }
}
