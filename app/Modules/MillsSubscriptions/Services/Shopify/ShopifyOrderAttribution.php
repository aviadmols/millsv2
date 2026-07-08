<?php

namespace App\Modules\MillsSubscriptions\Services\Shopify;

/**
 * The ONLY source of order attribution (CLAUDE.md law #11, D17). Every Shopify
 * order the system creates runs through apply() so it lands under the app's Sales
 * Channel: `source_name` MUST equal the channel handle (that is what populates the
 * native Channel column — a plain custom source_name does not). note_attributes +
 * a tag are defence-in-depth for visibility.
 */
final class ShopifyOrderAttribution
{
    // === CONSTANTS ===
    public const ROLE_RECURRING = 'recurring';

    public const ROLE_UPSELL = 'upsell';

    public static function sourceName(): string
    {
        return (string) config('shopify.order_source_name', 'mills-subscriptions');
    }

    public static function tag(): string
    {
        return (string) config('shopify.sales_channel_handle', 'mills-subscriptions');
    }

    /**
     * @return list<array{name: string, value: string}>
     */
    public static function noteAttributes(int $subscriptionId, string $role = self::ROLE_RECURRING): array
    {
        return [
            ['name' => 'mills_subscription_id', 'value' => (string) $subscriptionId],
            ['name' => 'mills_order_role', 'value' => $role],
        ];
    }

    /**
     * Stamp a REST order payload with channel attribution. Merges tags/note
     * attributes rather than replacing whatever the caller already set.
     *
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    public static function apply(array $order, int $subscriptionId, string $role = self::ROLE_RECURRING): array
    {
        $order['source_name'] = self::sourceName();

        $existingTags = array_filter(array_map('trim', explode(',', (string) ($order['tags'] ?? ''))));
        $existingTags[] = self::tag();
        $order['tags'] = implode(', ', array_values(array_unique($existingTags)));

        $order['note_attributes'] = array_merge(
            $order['note_attributes'] ?? [],
            self::noteAttributes($subscriptionId, $role),
        );

        return $order;
    }
}
