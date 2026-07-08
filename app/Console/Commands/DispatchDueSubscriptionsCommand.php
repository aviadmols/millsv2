<?php

namespace App\Console\Commands;

use App\Domain\Billing\IdempotencyKey;
use App\Jobs\ChargeSubscriptionJob;
use App\Models\Subscription;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * The recurring-charge dispatcher (ARCHITECTURE.md §5) — the fix for v1's broken
 * cron. Runs every 5 min on the dedicated scheduler service; selects subscriptions
 * whose next_charge_at is due (window, with automatic catch-up) and whose retry
 * backoff has elapsed, and queues one ChargeSubscriptionJob each. No cache toggle;
 * the only off switch is BILLING_KILL_SWITCH.
 */
class DispatchDueSubscriptionsCommand extends Command
{
    protected $signature = 'mills:dispatch-due {--chunk=100}';

    protected $description = 'Queue a charge for every subscription whose recurring charge is due.';

    public function handle(): int
    {
        if (config('billing.kill_switch')) {
            $this->warn('BILLING_KILL_SWITCH is on — no charges dispatched.');
            Cache::forever('billing.dispatch.last_run', now());

            return self::SUCCESS;
        }

        $cutoff = now()->addMinutes((int) config('billing.dispatch_window_minutes', 0));
        $chunk = (int) $this->option('chunk');
        $dispatched = 0;

        Subscription::query()
            ->where('status', SubscriptionStatus::ACTIVE->value)
            ->where('payment_state', PaymentState::PAYME->value)
            ->whereNotNull('next_charge_at')
            ->where('next_charge_at', '<=', $cutoff)
            ->where(function ($q) {
                $q->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now());
            })
            ->orderBy('id')
            ->chunkById($chunk, function ($subscriptions) use (&$dispatched) {
                foreach ($subscriptions as $subscription) {
                    // Pin the idempotency key to THIS cycle's due date so a
                    // re-dispatch for the same cycle collapses (the key is stable
                    // even after next_charge_at advances on success).
                    $key = IdempotencyKey::recurring(
                        $subscription->id,
                        $subscription->next_charge_at->toDateString(),
                    );
                    ChargeSubscriptionJob::dispatch($subscription->id, $key);
                    $dispatched++;
                }
            });

        Cache::forever('billing.dispatch.last_run', now());
        $this->info("Dispatched {$dispatched} charge job(s).");

        return self::SUCCESS;
    }
}
