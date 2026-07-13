<?php

namespace App\Observers;

use App\Models\Dog;
use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Services\Shopify\DraftOrderService;
use Throwable;

/**
 * Change a dog's food, and the next order changes with it.
 *
 * This is not a nicety. The upcoming order IS the next charge — its total is stored as
 * `next_charge_amount` and handed to PayMe. So a dog whose flavour is swapped without the
 * order being rebuilt leaves the screen showing one product, the customer being charged for
 * another, and the box containing a third. The three must not be allowed to drift.
 *
 * It runs SYNCHRONOUSLY rather than on a queue on purpose: a queued rebuild that never runs
 * (a stopped worker, a lost job) would leave a stale amount to be charged, which is exactly
 * the failure this exists to prevent. A slow save is a far better trade than a wrong charge.
 *
 * A Shopify failure is logged loudly and never blocks the save — the local DB is the source
 * of truth, and refusing to record what the admin did because a downstream call failed would
 * be worse than a stale draft that the next rebuild will fix.
 */
class DogObserver
{
    /** The fields that decide what is actually in the box. */
    private const ORDER_AFFECTING = ['selected_variants', 'addons_products', 'double_food', 'status'];

    public function saved(Dog $dog): void
    {
        if (! $dog->wasChanged(self::ORDER_AFFECTING)) {
            return;
        }

        $this->rebuild($dog);
    }

    public function deleted(Dog $dog): void
    {
        $this->rebuild($dog);
    }

    private function rebuild(Dog $dog): void
    {
        $subscription = $dog->subscription;

        if ($subscription === null) {
            return;
        }

        /*
         * A hand-edited upcoming order was written against the OLD products. Now that the
         * products themselves have changed, keeping it would hide the change the admin just
         * made — so the edit is dropped and the order is rebuilt from what the dog now eats.
         */
        if ($subscription->line_items_override !== null) {
            $subscription->forceFill([
                'line_items_override' => null,
                'line_items_overridden_at' => null,
            ])->save();

            SystemLog::info('admin', "the hand-edited upcoming order was dropped — the dog's products changed", [], [
                'subscription_id' => $subscription->id,
                'customer_id' => $subscription->customer_id,
            ]);
        }

        try {
            $draft = app(DraftOrderService::class)->refresh($subscription->fresh());

            SystemLog::info('shopify', 'the upcoming order was rebuilt after a product change', [
                'dog_id' => $dog->id,
                'new_total' => $draft['total'] ?? null,
            ], ['subscription_id' => $subscription->id, 'customer_id' => $subscription->customer_id]);
        } catch (Throwable $e) {
            // Never block the save. The DB is the truth; the draft is a projection of it, and
            // the next rebuild will catch up.
            SystemLog::error('shopify', 'could not rebuild the upcoming order after a product change', [
                'dog_id' => $dog->id,
                'message' => $e->getMessage(),
            ], ['subscription_id' => $subscription->id, 'customer_id' => $subscription->customer_id]);
        }
    }
}
