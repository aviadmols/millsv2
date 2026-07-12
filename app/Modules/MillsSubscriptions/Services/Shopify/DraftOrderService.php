<?php

namespace App\Modules\MillsSubscriptions\Services\Shopify;

use App\Models\Subscription;
use App\Models\SystemLog;
use App\Support\ShopifyId;
use RuntimeException;

/**
 * The "upcoming order" preview (ARCHITECTURE.md §5). Each active subscription
 * keeps one OPEN Shopify draft order that shows the customer what will ship next;
 * the recurring charge itself does NOT go through the draft (money moves via PayMe
 * and the paid order is created directly with an inline transaction).
 *
 * Line items are derived from the dogs' selected flavor variants + add-ons.
 */
class DraftOrderService
{
    private const CREATE = <<<'GQL'
    mutation($input: DraftOrderInput!) {
      draftOrderCreate(input: $input) {
        draftOrder { id name totalPrice }
        userErrors { field message }
      }
    }
    GQL;

    private const UPDATE = <<<'GQL'
    mutation($id: ID!, $input: DraftOrderInput!) {
      draftOrderUpdate(id: $id, input: $input) {
        draftOrder { id name totalPrice }
        userErrors { field message }
      }
    }
    GQL;

    private const GET = <<<'GQL'
    query($id: ID!) {
      draftOrder(id: $id) {
        id name totalPrice createdAt status
        lineItems(first: 50) { nodes { title quantity sku originalUnitPriceSet { shopMoney { amount currencyCode } } } }
      }
    }
    GQL;

    public function __construct(private readonly ShopifyAdminClient $client) {}

    /** @return array<string, mixed> */
    public function create(Subscription $subscription): array
    {
        $this->assertConnected();

        $result = $this->client->graphql(self::CREATE, [
            'input' => $this->input($subscription),
        ]);

        $draft = $this->unwrap($result, 'draftOrderCreate');
        $subscription->forceFill(['draft_order_id' => $draft['id'] ?? null])->save();

        SystemLog::info('shopify', 'draft order created', [
            'draft_order_id' => $draft['id'] ?? null,
        ], ['subscription_id' => $subscription->id, 'customer_id' => $subscription->customer_id]);

        return $draft;
    }

    /** @return array<string, mixed> */
    public function update(Subscription $subscription): array
    {
        $this->assertConnected();

        if (empty($subscription->draft_order_id)) {
            return $this->create($subscription);
        }

        $result = $this->client->graphql(self::UPDATE, [
            'id' => ShopifyId::gid((string) $subscription->draft_order_id, 'DraftOrder'),
            'input' => $this->input($subscription),
        ]);

        $draft = $this->unwrap($result, 'draftOrderUpdate');

        SystemLog::info('shopify', 'draft order updated', [
            'draft_order_id' => $subscription->draft_order_id,
        ], ['subscription_id' => $subscription->id, 'customer_id' => $subscription->customer_id]);

        return $draft;
    }

    /** @return array<string, mixed> */
    public function get(Subscription $subscription): array
    {
        $this->assertConnected();

        if (empty($subscription->draft_order_id)) {
            return [];
        }

        $result = $this->client->graphql(self::GET, [
            'id' => ShopifyId::gid((string) $subscription->draft_order_id, 'DraftOrder'),
        ]);

        return $result['data']['draftOrder'] ?? [];
    }

    /**
     * Build the draft from the subscription's dogs: every selected flavor variant
     * and every add-on becomes a line item.
     *
     * @return array<string, mixed>
     */
    private function input(Subscription $subscription): array
    {
        $subscription->loadMissing(['dogs', 'customer']);

        $lineItems = [];
        foreach ($subscription->dogs as $dog) {
            if (($dog->status ?? 'active') !== 'active') {
                continue;
            }

            $variants = array_merge(
                (array) ($dog->selected_variants ?? []),
                (array) ($dog->addons_products ?? []),
            );

            foreach ($variants as $variant) {
                $id = is_array($variant) ? ($variant['id'] ?? null) : $variant;
                if (empty($id)) {
                    continue;
                }

                $lineItems[] = [
                    'variantId' => ShopifyId::gid((string) $id, 'ProductVariant'),
                    'quantity' => $dog->double_food ? 2 : 1,
                ];
            }
        }

        $input = ['lineItems' => $lineItems];

        if (! empty($subscription->customer?->shopify_customer_id)) {
            $input['purchasingEntity'] = [
                'customerId' => ShopifyId::gid((string) $subscription->customer->shopify_customer_id, 'Customer'),
            ];
        }

        return $input;
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
