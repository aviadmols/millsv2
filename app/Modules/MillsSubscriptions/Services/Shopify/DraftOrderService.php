<?php

namespace App\Modules\MillsSubscriptions\Services\Shopify;

use App\Models\Subscription;
use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Support\VariantResolver;
use App\Support\ShopifyId;
use RuntimeException;

/**
 * The "upcoming order" — one OPEN Shopify draft order per active subscription, showing
 * exactly what will ship next and what it will cost.
 *
 * The recurring charge itself does NOT go through the draft: money moves via PayMe and
 * the paid order is created directly with an inline transaction. The draft is the
 * preview, and it is what the admin (and, through /me, the customer) look at.
 *
 * Line items come from the dogs' selected flavour variants plus their add-ons.
 */
class DraftOrderService
{
    private const DRAFT_FIELDS = <<<'GQL'
    id
    name
    status
    createdAt
    totalPriceSet { shopMoney { amount currencyCode } }
    subtotalPriceSet { shopMoney { amount currencyCode } }
    lineItems(first: 50) {
      nodes {
        title
        quantity
        sku
        image { url }
        variant { id image { url } }
        originalUnitPriceSet { shopMoney { amount } }
      }
    }
    GQL;

    private const CREATE = <<<'GQL'
    mutation($input: DraftOrderInput!) {
      draftOrderCreate(input: $input) {
        draftOrder { %FIELDS% }
        userErrors { field message }
      }
    }
    GQL;

    private const UPDATE = <<<'GQL'
    mutation($id: ID!, $input: DraftOrderInput!) {
      draftOrderUpdate(id: $id, input: $input) {
        draftOrder { %FIELDS% }
        userErrors { field message }
      }
    }
    GQL;

    private const GET = <<<'GQL'
    query($id: ID!) {
      draftOrder(id: $id) { %FIELDS% }
    }
    GQL;

    public function __construct(private readonly ShopifyAdminClient $client) {}

    /**
     * The subscription's upcoming order, creating it if it does not exist yet.
     *
     * This is the entry point for the admin screen. It never throws for a merely
     * "empty" subscription — a subscription with no products simply has no upcoming
     * order, and saying so is more useful than an error.
     *
     * @return array<string, mixed> empty array when there is nothing to show
     */
    public function ensure(Subscription $subscription): array
    {
        if ($this->lineItems($subscription) === []) {
            return [];   // no products chosen → nothing to preview
        }

        if (! empty($subscription->draft_order_id)) {
            $existing = $this->get($subscription);

            // A draft that was completed or deleted in Shopify is no longer the
            // upcoming order — build a fresh one rather than showing a stale link.
            if ($existing !== [] && ($existing['status'] ?? 'OPEN') === 'OPEN') {
                return $existing;
            }
        }

        return $this->create($subscription);
    }

    /** @return array<string, mixed> */
    public function create(Subscription $subscription): array
    {
        $this->assertConnected();

        $result = $this->client->graphql($this->query(self::CREATE), [
            'input' => $this->input($subscription),
        ]);

        $draft = $this->unwrap($result, 'draftOrderCreate');
        $presented = $this->present($draft);

        $subscription->forceFill([
            'draft_order_id' => $presented['id'] ?: null,
        ])->save();

        $this->storeAmount($subscription, $presented);

        SystemLog::info('shopify', 'draft order created', [
            'draft_order_id' => $subscription->draft_order_id,
            'total' => $presented['total'] ?? null,
        ], ['subscription_id' => $subscription->id, 'customer_id' => $subscription->customer_id]);

        return $presented;
    }

    /** Rebuild the draft to match the subscription's current products. */
    public function refresh(Subscription $subscription): array
    {
        $this->assertConnected();

        if (empty($subscription->draft_order_id)) {
            return $this->create($subscription);
        }

        $result = $this->client->graphql($this->query(self::UPDATE), [
            'id' => ShopifyId::gid((string) $subscription->draft_order_id, 'DraftOrder'),
            'input' => $this->input($subscription),
        ]);

        $presented = $this->present($this->unwrap($result, 'draftOrderUpdate'));

        $this->storeAmount($subscription, $presented);

        SystemLog::info('shopify', 'draft order refreshed', [
            'draft_order_id' => $subscription->draft_order_id,
            'total' => $presented['total'] ?? null,
        ], ['subscription_id' => $subscription->id, 'customer_id' => $subscription->customer_id]);

        return $presented;
    }

    /**
     * The draft IS the next order, so its total IS the next charge. Storing it here is
     * what makes the number the admin reads and the number PayMe is asked for the same
     * number — rather than two independent guesses that can drift apart.
     *
     * @param  array<string, mixed>  $draft
     */
    private function storeAmount(Subscription $subscription, array $draft): void
    {
        $total = $draft['total'] ?? null;

        if ($total === null || (float) $total <= 0) {
            return;
        }

        $subscription->forceFill([
            'next_charge_amount' => (float) $total,
            'next_charge_amount_at' => now(),
        ])->save();
    }

    /** @return array<string, mixed> */
    public function get(Subscription $subscription): array
    {
        $this->assertConnected();

        if (empty($subscription->draft_order_id)) {
            return [];
        }

        $result = $this->client->graphql($this->query(self::GET), [
            'id' => ShopifyId::gid((string) $subscription->draft_order_id, 'DraftOrder'),
        ]);

        $draft = $result['data']['draftOrder'] ?? null;

        return is_array($draft) ? $this->present($draft) : [];
    }

    /**
     * Every variant this subscription bills for: each active dog's flavours and add-ons.
     *
     * @return list<array{variantId: string, quantity: int}>
     */
    public function lineItems(Subscription $subscription): array
    {
        $subscription->loadMissing('dogs');

        $items = [];

        foreach ($subscription->dogs as $dog) {
            if (($dog->status ?? 'active') !== 'active') {
                continue;
            }

            $variants = array_merge(
                VariantResolver::normalise($dog->selected_variants),
                VariantResolver::normalise($dog->addons_products),
            );

            foreach ($variants as $variantId) {
                $items[] = [
                    'variantId' => ShopifyId::gid($variantId, 'ProductVariant'),
                    // v1 always ships quantity 1 — the pack size, not the quantity, is
                    // what varies. `double_food` is the one exception it honoured.
                    'quantity' => $dog->double_food ? 2 : 1,
                ];
            }
        }

        return $items;
    }

    /** @return array<string, mixed> */
    private function input(Subscription $subscription): array
    {
        $subscription->loadMissing('customer');

        $input = ['lineItems' => $this->lineItems($subscription)];

        if (! empty($subscription->customer?->shopify_customer_id)) {
            $input['purchasingEntity'] = [
                'customerId' => ShopifyId::gid((string) $subscription->customer->shopify_customer_id, 'Customer'),
            ];
        }

        // Subscribers do not pay list price. The real orders bill ₪171.00 of product less
        // exactly 10% — and v1 posted `discount: 0.9` with every dog. Omitting it would
        // OVERCHARGE every customer by the discount they have always had.
        $discount = (float) ($subscription->discount_percent ?? 0);

        if ($discount > 0) {
            $input['appliedDiscount'] = [
                'valueType' => 'PERCENTAGE',
                'value' => $discount,
                'title' => __('subscriptions.discount_title'),
            ];
        }

        // No shipping line: subscription delivery is free (D-shipping). The historical
        // ₪29 "משלוח עד הבית" belongs to the old one-off checkout, not the recurring cycle.

        return $input;
    }

    /**
     * Flatten Shopify's draft into the shape the admin renders — including the TOTAL
     * and a product image per line, so the screen shows what is coming and what it costs.
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private function present(array $draft): array
    {
        if ($draft === []) {
            return [];
        }

        $id = ShopifyId::numeric((string) ($draft['id'] ?? ''));

        $lineItems = [];
        foreach ($draft['lineItems']['nodes'] ?? [] as $item) {
            $variantId = ShopifyId::numeric((string) ($item['variant']['id'] ?? '')) ?: null;

            $lineItems[] = [
                'title' => $item['title'] ?? '—',
                'quantity' => (int) ($item['quantity'] ?? 1),
                'sku' => $item['sku'] ?? null,
                'variant_id' => $variantId,
                'price' => $item['originalUnitPriceSet']['shopMoney']['amount'] ?? null,
                'image_url' => $item['variant']['image']['url']
                    ?? $item['image']['url']
                    ?? ($variantId ? VariantResolver::resolve([$variantId])->first()?->image_url : null),
            ];
        }

        return [
            'id' => $id,
            'name' => $draft['name'] ?? null,
            'status' => $draft['status'] ?? null,
            'admin_url' => OrderHistoryService::adminUrl('draft_orders', $id),
            'total' => $draft['totalPriceSet']['shopMoney']['amount'] ?? null,
            'subtotal' => $draft['subtotalPriceSet']['shopMoney']['amount'] ?? null,
            'currency' => $draft['totalPriceSet']['shopMoney']['currencyCode'] ?? 'ILS',
            'line_items' => $lineItems,
        ];
    }

    private function query(string $template): string
    {
        return str_replace('%FIELDS%', self::DRAFT_FIELDS, $template);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function unwrap(array $result, string $mutation): array
    {
        $errors = $result['data'][$mutation]['userErrors'] ?? ($result['errors'] ?? []);

        if (! empty($errors)) {
            SystemLog::error('shopify', "{$mutation} failed", ['errors' => $errors]);

            throw new RuntimeException('shopify_draft_order_failed');
        }

        return $result['data'][$mutation]['draftOrder'] ?? [];
    }

    private function assertConnected(): void
    {
        if (! $this->client->isConnected()) {
            throw new RuntimeException('shopify_not_connected');
        }
    }
}
