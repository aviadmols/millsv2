<?php

namespace App\Modules\MillsSubscriptions\Services\Shopify;

use App\Models\DiscountRule;
use App\Models\Subscription;
use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Support\DiscountResolver;
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

    private const DELETE = <<<'GQL'
    mutation($input: DraftOrderDeleteInput!) {
      draftOrderDelete(input: $input) {
        deletedId
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

    /**
     * Rebuild the draft to match the subscription's current products.
     *
     * DELETE and CREATE, never update-in-place. draftOrderUpdate keeps whatever the input
     * does not mention — and it kept the order-level discount even when the input said
     * `appliedDiscount: null` explicitly: subscription 469's draft was "rebuilt" on
     * 2026-09-02 and came back with the identical stacked total, 594 × 0.9 × 0.9. A fresh
     * draft inherits nothing, so what we send is ALL there is. The draft number changes;
     * nothing depends on it — it is a preview object, and the new id is stored right here.
     */
    public function refresh(Subscription $subscription): array
    {
        $this->assertConnected();

        if (empty($subscription->draft_order_id)) {
            return $this->create($subscription);
        }

        try {
            $result = $this->client->graphql(self::DELETE, [
                'input' => ['id' => ShopifyId::gid((string) $subscription->draft_order_id, 'DraftOrder')],
            ]);
            $this->unwrap($result, 'draftOrderDelete');
        } catch (Throwable $e) {
            /*
             * A draft that was already completed or deleted cannot be deleted again — and
             * either way it is not the upcoming order any more. The fresh CREATE below is
             * the answer in every one of those cases; only note what happened.
             */
            SystemLog::info('shopify', 'old draft could not be deleted before rebuild — creating fresh anyway', [
                'draft_order_id' => $subscription->draft_order_id,
                'message' => $e->getMessage(),
            ], ['subscription_id' => $subscription->id, 'customer_id' => $subscription->customer_id]);
        }

        $subscription->forceFill(['draft_order_id' => null])->save();

        $presented = $this->create($subscription);

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
     * Every variant this subscription bills for.
     *
     * THE SINGLE SOURCE for what goes in the box. The draft preview, the paid order and the
     * charge amount all come through here, so they cannot drift apart — an admin who edits
     * the upcoming order must not end up charging for one thing and shipping another.
     *
     * A hand-edited order (line_items_override) wins over the dogs' products for the cycle
     * it was made for. Otherwise the lines are derived: each active dog's flavours and
     * add-ons.
     *
     * @return list<array{variantId: string, quantity: int}>
     */
    public function lineItems(Subscription $subscription): array
    {
        // NULL means "never edited" → derive from the dogs. An override that exists but is
        // empty means "the admin removed everything" — falling back to the dogs there would
        // ship exactly what they just deleted.
        if ($subscription->line_items_override !== null) {
            return $this->overrideLineItems($subscription);
        }

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

    /**
     * @return list<array{variantId: string, quantity: int}>
     */
    private function overrideLineItems(Subscription $subscription): array
    {
        $items = [];

        foreach ((array) ($subscription->line_items_override ?? []) as $line) {
            $variantId = ShopifyId::numeric((string) ($line['variant_id'] ?? ''));
            $quantity = (int) ($line['quantity'] ?? 1);

            // A zero-quantity line is a removal, not a line — a Shopify order with a 0 there
            // is rejected, and silently sending 1 instead would ship what the admin deleted.
            if ($variantId === '' || $quantity < 1) {
                continue;
            }

            $items[] = [
                'variantId' => ShopifyId::gid($variantId, 'ProductVariant'),
                'quantity' => $quantity,
            ];
        }

        return $items;
    }

    /** @return array<string, mixed> */
    private function input(Subscription $subscription): array
    {
        $subscription->loadMissing('customer');

        $lineItems = $this->lineItems($subscription);
        $input = ['lineItems' => $lineItems];

        if (! empty($subscription->customer?->shopify_customer_id)) {
            $input['purchasingEntity'] = [
                'customerId' => ShopifyId::gid((string) $subscription->customer->shopify_customer_id, 'Customer'),
            ];
        }

        /*
         * A discount only if one was earned. v1 stacked 10% on every recurring order on top
         * of the price the products already carry in the store; since 2026-08-30 nobody is
         * discounted by default, and what they get instead is decided by the discount rules
         * — matched against this exact order — or by a rate an admin set on the subscription
         * itself, which outranks every rule.
         */
        $decision = DiscountResolver::for($subscription, $lineItems);

        /*
         * ALWAYS set the order-level discount field — to a value or to null — never omit
         * it. draftOrderUpdate leaves any field it is not given untouched, so a draft that
         * carried the old order-wide 10% KEPT it when the input said nothing about it, and
         * the new line-level discount landed on top: the customer discounted twice, and the
         * doubly-reduced total stored as the amount to charge. Found on subscription 469
         * after the 2026-09-01 sweep quietly did this to the whole book.
         */
        $input['appliedDiscount'] = null;

        if ($decision !== null) {
            $input = $this->applyDiscount($input, $decision);
        }

        // No shipping line: subscription delivery is free (D-shipping). The historical
        // ₪29 "משלוח עד הבית" belongs to the old one-off checkout, not the recurring cycle.

        return $input;
    }

    /**
     * Put the decided discount on the draft — on the order, or on the lines it applies to.
     *
     * Shopify takes a percentage either way; the difference is where it hangs. A line-scoped
     * rule must NOT also set an order-level discount, or Shopify applies both and the
     * customer is discounted twice for one rule.
     *
     * @param  array<string, mixed>  $input
     * @param  array{percent: float, scope: string, rule_name: ?string, variant_ids: list<string>}  $decision
     * @return array<string, mixed>
     */
    private function applyDiscount(array $input, array $decision): array
    {
        $title = $decision['rule_name'] ?: __('subscriptions.discount_title');

        if ($decision['scope'] !== DiscountRule::SCOPE_MATCHING_LINES) {
            $input['appliedDiscount'] = [
                'valueType' => 'PERCENTAGE',
                'value' => $decision['percent'],
                'title' => $title,
            ];

            return $input;
        }

        $targets = array_flip($decision['variant_ids']);

        foreach ($input['lineItems'] as $i => $line) {
            if (! isset($targets[ShopifyId::numeric((string) $line['variantId'])])) {
                continue;
            }

            $input['lineItems'][$i]['appliedDiscount'] = [
                'valueType' => 'PERCENTAGE',
                'value' => $decision['percent'],
                'title' => $title,
            ];
        }

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
