<?php

namespace App\Http\Controllers\Storefront;

use App\Models\Subscription;
use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Services\Shopify\DraftOrderService;
use App\Modules\MillsSubscriptions\Support\OrderCatalog;
use App\Modules\MillsSubscriptions\Support\Timeline;
use App\Support\ShopifyId;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * The customer editing their upcoming order from the personal area (2026-11-12).
 *
 * Every change applies to ALL the orders that follow, food or extra alike (Aviad,
 * 2026-11-12). The two kinds only live in different places:
 *  - FOOD is the dog's food (selected_variants / double_food, 1 or 2 of each line), written
 *    on the dog. DogObserver rebuilds the upcoming order and the stored amount.
 *  - EXTRAS — tasting boxes, snacks, toys — are subscriptions.recurring_addons, any
 *    quantity up to MAX_ADDON_QUANTITY, shipped with every order until the customer
 *    removes them.
 *
 * Both are billing-affecting, so both sit behind the card-update wall, and a cancelled
 * subscription cannot be edited at all.
 */
class StorefrontOrderEditorController extends AbstractStorefrontController
{
    /** A dog holds a single or a double portion — DraftOrderService ships 1 or 2. */
    private const MAX_FOOD_QUANTITY = 2;

    private const MAX_ADDON_QUANTITY = 10;

    private const MAX_ADDON_LINES = 20;

    /** GET me/catalog — what can go into an order, split into food and extras. */
    public function catalog(Request $request): JsonResponse
    {
        $this->requireCustomer($request);

        $food = [];
        $addons = [];

        foreach (OrderCatalog::selectable() as $variant) {
            $entry = OrderCatalog::present($variant);

            if ($entry['kind'] === OrderCatalog::KIND_FOOD) {
                $food[] = $entry;
            } else {
                $addons[] = $entry;
            }
        }

        return $this->ok(['food' => $food, 'addons' => $addons]);
    }

    /** PUT me/dogs/{id}/food — {variant_ids: [...], quantity: 1|2}. Permanent. */
    public function updateFood(Request $request, string $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $dog = $this->findOwnedDog($customer, $id);

        if ($dog->subscription === null) {
            return $this->fail('dog_not_in_subscription', 'הכלב אינו משויך למנוי.', 422);
        }

        $this->guardEditable($dog->subscription);

        $ids = array_values(array_unique(array_filter(array_map(
            fn ($v) => ShopifyId::numeric((string) $v),
            $this->idList($request, ['variant_ids', 'variantIds']),
        ))));
        $quantity = (int) $request->input('quantity', 1);

        if ($ids === []) {
            return $this->fail('food_required', 'יש לבחור לפחות מזון אחד לכלב.', 422);
        }

        if ($quantity < 1 || $quantity > self::MAX_FOOD_QUANTITY) {
            return $this->fail('invalid_quantity', 'אפשר לבחור כמות של 1 או 2 בלבד.', 422);
        }

        // The dog's CURRENT food may be a variant the catalog no longer lists (the hidden
        // subscription product, a retired flavour): keeping it is allowed, adding one is not.
        $current = array_map(fn ($v) => (string) (is_array($v) ? ($v['id'] ?? '') : $v), (array) ($dog->selected_variants ?? []));
        $current = array_map(fn ($v) => ShopifyId::numeric((string) $v), $current);
        $new = array_values(array_diff($ids, $current));

        if ($new !== [] && OrderCatalog::resolve($new, OrderCatalog::KIND_FOOD) === null) {
            return $this->fail('invalid_food', 'אחד המוצרים שנבחרו אינו מזון זמין.', 422);
        }

        $dog->selected_variants = array_values(array_map('strval', $ids));
        $dog->double_food = $quantity === 2;
        $dog->save();

        SystemLog::info('storefront', 'dog food changed by the customer (permanent)', [
            'variants' => $dog->selected_variants,
            'quantity' => $quantity,
        ], ['subscription_id' => $dog->subscription_id, 'customer_id' => $customer->id]);

        return $this->ok(['subscription_id' => $dog->subscription_id]);
    }

    /** PATCH me/subscription/{id}/addons — {items: [{variant_id, quantity}]}. Every order from now on. */
    public function updateAddons(Request $request, string $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $subscription = $this->findOwnedSubscription($customer, $id);
        $this->guardEditable($subscription);

        $items = [];

        foreach ((array) $request->input('items', []) as $item) {
            $variantId = ShopifyId::numeric((string) (is_array($item) ? ($item['variant_id'] ?? '') : ''));
            $quantity = (int) (is_array($item) ? ($item['quantity'] ?? 1) : 0);

            if ($variantId === '' || $quantity < 1) {
                continue;
            }

            if ($quantity > self::MAX_ADDON_QUANTITY) {
                return $this->fail('invalid_quantity', 'כמות מקסימלית לתוספת היא '.self::MAX_ADDON_QUANTITY.'.', 422);
            }

            $items[$variantId] = ['variant_id' => $variantId, 'quantity' => ($items[$variantId]['quantity'] ?? 0) + $quantity];
        }

        $items = array_values($items);

        if (count($items) > self::MAX_ADDON_LINES) {
            return $this->fail('too_many_lines', 'יותר מדי תוספות בהזמנה אחת.', 422);
        }

        if ($items !== [] && OrderCatalog::resolve(array_column($items, 'variant_id'), OrderCatalog::KIND_ADDON) === null) {
            return $this->fail('invalid_addon', 'אחת התוספות שנבחרו אינה זמינה.', 422);
        }

        $subscription->forceFill([
            'recurring_addons' => $items === [] ? null : $items,
            // The customer's latest word wins over an admin's one-off edit of the same order.
            'line_items_override' => null,
            'line_items_overridden_at' => null,
        ])->save();

        $this->rebuildUpcomingOrder($subscription);

        Timeline::record(Timeline::KIND_NOTE, [
            'note' => $items === []
                ? __('subscriptions.addons_cleared_note')
                : __('subscriptions.addons_updated_note', ['count' => array_sum(array_column($items, 'quantity'))]),
        ], $subscription->id, $customer->id, Timeline::ACTOR_CUSTOMER);

        SystemLog::info('storefront', 'recurring extras changed by the customer', [
            'items' => $items,
        ], ['subscription_id' => $subscription->id, 'customer_id' => $customer->id]);

        return $this->ok(['subscription_id' => $subscription->id, 'recurring_addons' => $items]);
    }

    private function guardEditable(Subscription $subscription): void
    {
        if ($subscription->status === SubscriptionStatus::CANCELLED) {
            throw new HttpResponseException(
                $this->fail('subscription_cancelled', 'המנוי בוטל ולא ניתן לערוך אותו.', 422),
            );
        }

        $this->guardCardUpdate($subscription);
    }

    /** Best-effort, like every other storefront write: the DB is the truth, the draft follows. */
    private function rebuildUpcomingOrder(Subscription $subscription): void
    {
        try {
            app(DraftOrderService::class)->refresh($subscription->fresh());
        } catch (Throwable $e) {
            SystemLog::warning('shopify', 'could not rebuild the upcoming order after a customer edit', [
                'message' => $e->getMessage(),
            ], ['subscription_id' => $subscription->id, 'customer_id' => $subscription->customer_id]);
        }
    }
}
