<?php

namespace App\Modules\MillsSubscriptions\Support;

use App\Models\Customer;
use App\Models\Dog;
use App\Models\Subscription;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;

/**
 * Maps the v2 DB read-model onto the FROZEN theme payload shapes (SYSTEM-MAP §3.3).
 * The Shopify theme is unchanged, so the JSON it receives must keep the v1 field
 * names and value vocabulary even though the underlying model is different:
 *
 *  - v1 `integration_source` ("payme"|"icount") ⇐ v2 `payment_state`. The theme
 *    keys its card-update banner off `icount`/`requires_card_update`, so a
 *    subscription needing a card update is presented as "icount" to keep that
 *    logic working byte-for-byte. v2 itself has no iCount.
 *  - v1 status vocabulary is active|pending|disable|paused ⇐ v2 SubscriptionStatus
 *    (cancelled is presented as "disable").
 *  - v1 `charge_cycle` (Y-m-d string) ⇐ v2 `next_charge_at` (datetime).
 *  - Ids are presented as the legacy Shopify GID when the row was imported, else
 *    the numeric DB id. Both forms are accepted back on writes (see ResolvesRecords).
 */
final class StorefrontPresenter
{
    /** @return array<string, mixed> */
    public static function me(Customer $customer): array
    {
        $customer->loadMissing(['subscriptions.dogs', 'dogs']);

        $subscriptions = $customer->subscriptions
            ->map(fn (Subscription $s) => self::subscription($s))
            ->values()
            ->all();

        $anyRequiresCardUpdate = $customer->subscriptions
            ->contains(fn (Subscription $s) => $s->payment_state === PaymentState::NEEDS_CARD_UPDATE);

        $dogs = $customer->dogs->map(fn (Dog $d) => self::dog($d))->values()->all();

        return [
            'customer' => self::customer($customer),
            'subscriptions' => $subscriptions,
            'dogs' => $dogs,
            'flags' => [
                'is_icount' => $anyRequiresCardUpdate,
                'any_requires_card_update' => $anyRequiresCardUpdate,
                'is_legacy_note' => false,   // v2 has no legacy-note customers (imported once)
                'is_empty' => $subscriptions === [] && $dogs === [],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function customer(Customer $customer): array
    {
        return [
            'id' => self::customerId($customer),
            'numeric_id' => (string) ($customer->shopify_customer_id ?? $customer->id),
            'email' => $customer->email,
            'display_name' => $customer->fullName(),
            'addresses' => array_values(array_filter([self::address($customer)])),
        ];
    }

    /** @return array<string, mixed>|null */
    public static function address(Customer $customer): ?array
    {
        $address = [
            'firstName' => $customer->first_name,
            'lastName' => $customer->last_name,
            'address1' => $customer->address1,
            'address2' => $customer->address2,
            'city' => $customer->city,
            'province' => $customer->province,
            'country' => $customer->country,
            'zip' => $customer->zip,
            'phone' => $customer->phone,
        ];

        return array_filter($address, fn ($v) => $v !== null && $v !== '') === [] ? null : $address;
    }

    /** @return array<string, mixed> */
    public static function subscription(Subscription $subscription): array
    {
        $subscription->loadMissing('dogs');

        $requiresCardUpdate = $subscription->payment_state === PaymentState::NEEDS_CARD_UPDATE;
        $dogs = $subscription->dogs;

        return [
            'id' => self::subscriptionId($subscription),
            'numeric_id' => (string) $subscription->id,
            'status' => self::status($subscription->status),
            'frequency' => self::frequency($subscription->frequency_months),
            'frequency_months' => (int) $subscription->frequency_months,
            'charge_cycle' => $subscription->next_charge_at?->format('Y-m-d'),
            'integration_source' => $requiresCardUpdate ? 'icount' : 'payme',
            'requires_card_update' => $requiresCardUpdate,
            'draft_order_id' => $subscription->draft_order_id,
            'original_order_id' => $subscription->original_order_id,
            'dogs' => $dogs->map(fn (Dog $d) => self::dog($d))->values()->all(),
            'subscription_products' => self::subscriptionProducts($subscription),
        ];
    }

    /**
     * The frozen products-per-dog map:
     * {"dogs": {"<dogId>": {"subscription_products": [...], "addons_products": [...]}}}
     *
     * @return array<string, mixed>
     */
    public static function subscriptionProducts(Subscription $subscription): array
    {
        $subscription->loadMissing('dogs');

        $dogs = [];
        foreach ($subscription->dogs as $dog) {
            $dogs[self::dogId($dog)] = [
                'subscription_products' => self::variantList($dog->selected_variants),
                'addons_products' => self::variantList($dog->addons_products),
            ];
        }

        return ['dogs' => $dogs];
    }

    /** @return array<string, mixed> */
    public static function dog(Dog $dog): array
    {
        return [
            'id' => self::dogId($dog),
            'numeric_id' => (string) $dog->id,
            'name' => $dog->name,
            'status' => $dog->status ?: 'active',
            'subscription_status' => $dog->subscription_status,
            'sex' => $dog->sex,
            'weight' => $dog->weight,
            'age' => $dog->age,
            'activity' => $dog->activity,
            'body' => $dog->body,
            'allergies' => $dog->allergies,
            'avatar' => $dog->avatar,
            'addons_products' => self::variantList($dog->addons_products),
            'selected_variants' => self::variantList($dog->selected_variants),
        ];
    }

    public static function subscriptionId(Subscription $subscription): string
    {
        return (string) ($subscription->legacy_shopify_gid ?: $subscription->id);
    }

    public static function dogId(Dog $dog): string
    {
        return (string) ($dog->legacy_shopify_gid ?: $dog->id);
    }

    public static function customerId(Customer $customer): string
    {
        return (string) ($customer->legacy_shopify_gid ?: $customer->shopify_customer_id ?: $customer->id);
    }

    /** v2 enum → the v1 status vocabulary the theme understands (active|pending|disable). */
    public static function status(?SubscriptionStatus $status): string
    {
        return ($status ?? SubscriptionStatus::PENDING)->toLegacy();
    }

    public static function frequency(?int $months): string
    {
        return (int) $months === 2 ? 'Every 2 Months' : 'Monthly';
    }

    /**
     * Variant id lists are stored as JSON arrays; always present them as a flat
     * list of strings so the theme can compare ids without type juggling.
     *
     * @return list<string>
     */
    private static function variantList(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $item = $item['id'] ?? null;
            }
            if ($item !== null && $item !== '') {
                $out[] = (string) $item;
            }
        }

        return $out;
    }
}
