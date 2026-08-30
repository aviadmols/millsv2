<?php

namespace App\Jobs;

use App\Models\Subscription;
use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Services\Shopify\DraftOrderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * One small CHUNK of a repricing sweep — never the whole book.
 *
 * Same shape, and same reason, as ImportLegacyCustomersJob: each refresh is a Shopify
 * round trip, so a job carrying hundreds of subscriptions would outlive the database
 * queue's 90-second release window, be picked up again while still running, and end up
 * stamped failed halfway through. A dozen at a time finishes well inside it, and a
 * Shopify hiccup costs one chunk rather than the run.
 *
 * A failed refresh is deliberately swallowed per subscription: the sweep must not abandon
 * 800 customers because one of them has a variant Shopify no longer sells. Those are
 * logged by id so they can be looked at, and their stored amount is simply left alone —
 * an old amount is a wrong price, but an amount invented after a failed lookup is worse.
 */
class RefreshUpcomingOrdersJob implements ShouldQueue
{
    use Queueable;

    /** Small enough that a chunk NEVER outlives the queue's release window. */
    public const CHUNK_SIZE = 12;

    public int $tries = 3;

    /** @param list<int> $subscriptionIds */
    public function __construct(public array $subscriptionIds)
    {
        $this->onQueue('sync');
    }

    public function handle(DraftOrderService $drafts): void
    {
        $repriced = 0;
        $unchanged = 0;
        $failed = 0;

        foreach (Subscription::query()->whereIn('id', $this->subscriptionIds)->get() as $subscription) {
            $before = $subscription->next_charge_amount;

            try {
                $drafts->refresh($subscription);
            } catch (Throwable $e) {
                $failed++;

                SystemLog::warning('billing', 'could not rebuild an upcoming order', [
                    'message' => $e->getMessage(),
                ], ['subscription_id' => $subscription->id, 'customer_id' => $subscription->customer_id]);

                continue;
            }

            $after = $subscription->fresh()?->next_charge_amount;

            if ((float) $before === (float) $after) {
                $unchanged++;

                continue;
            }

            $repriced++;

            // The money number changed under the customer's feet. That belongs in the log
            // with both figures, so anyone asking "why is my charge different" gets an answer.
            SystemLog::info('billing', 'upcoming order repriced', [
                'from' => $before,
                'to' => $after,
            ], ['subscription_id' => $subscription->id, 'customer_id' => $subscription->customer_id]);
        }

        SystemLog::info('billing', 'upcoming orders rebuilt (chunk)', [
            'repriced' => $repriced,
            'unchanged' => $unchanged,
            'failed' => $failed,
            'in_chunk' => count($this->subscriptionIds),
        ]);
    }
}
