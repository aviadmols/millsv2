<?php

namespace App\Console\Commands;

use App\Domain\Billing\IdempotencyKey;
use App\Http\Controllers\Api\CronApiController;
use App\Jobs\ChargeSubscriptionJob;
use App\Models\AppSetting;
use App\Models\Subscription;
use App\Models\SystemLog;
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
        // The only ways billing can be off — and both are LOUD (v1's fatal flaw was
        // a cache flag that silently defaulted to OFF).
        if (! CronApiController::isEnabled()) {
            $reason = config('billing.kill_switch') ? 'BILLING_KILL_SWITCH' : 'billing_enabled=0';
            $this->warn("Billing is disabled ({$reason}) — no charges dispatched.");
            SystemLog::warning('cron', 'billing dispatch skipped — billing is disabled', ['reason' => $reason]);
            Cache::forever('billing.dispatch.last_run', now()->toIso8601String());

            return self::SUCCESS;
        }

        /*
         * The admin-chosen billing hour, ISRAEL time.
         *
         * The scheduler ticks every five minutes around the clock, and next_charge_at is a
         * bare date — so without this gate the first tick after midnight charged everyone,
         * and customers woke to 00:05 charges and 00:06 order confirmations. Before the
         * chosen hour nothing dispatches; from it onward every tick proceeds as usual, so a
         * missed tick (deploy, crash) is caught up the same day. Idempotency keys make the
         * repeats collapse.
         */
        $billingHour = max(0, min(23, (int) AppSetting::get('billing_hour', 9)));

        if (now('Asia/Jerusalem')->hour < $billingHour) {
            // last_run still moves: "waiting for 09:00" is billing WORKING, and the
            // dashboard's CRON light must not go red over it. Quiet on purpose — a
            // SystemLog row every five minutes all night is noise nobody reads.
            Cache::forever('billing.dispatch.last_run', now()->toIso8601String());
            $this->info(sprintf('Waiting for the billing hour (%02d:00 Israel).', $billingHour));

            return self::SUCCESS;
        }

        $cutoff = now()->addMinutes((int) config('billing.dispatch_window_minutes', 0));
        $chunk = (int) $this->option('chunk');
        $dispatched = 0;
        $heldBack = 0;

        Subscription::query()
            ->where('status', SubscriptionStatus::ACTIVE->value)
            ->where('payment_state', PaymentState::PAYME->value)
            ->whereNotNull('next_charge_at')
            ->where('next_charge_at', '<=', $cutoff)
            ->where(function ($q) {
                $q->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now());
            })
            ->orderBy('id')
            ->chunkById($chunk, function ($subscriptions) use (&$dispatched, &$heldBack) {
                foreach ($subscriptions as $subscription) {
                    /*
                     * A subscription more than one whole cycle behind is NOT charged.
                     *
                     * A successful charge advances next_charge_at from the OLD due date, so a
                     * subscription stuck two months back would be charged for the first missed
                     * cycle, land on a date that is STILL in the past, and be charged again
                     * five minutes later — two months of billing in ten minutes, for boxes
                     * that were never shipped. It waits for a person instead.
                     */
                    if ($subscription->isTooFarBehindToCharge()) {
                        $heldBack++;

                        SystemLog::warning('cron', 'a subscription is too far behind to charge automatically', [
                            'next_charge_at' => $subscription->next_charge_at->toDateString(),
                            'frequency_months' => $subscription->frequency_months,
                        ], ['subscription_id' => $subscription->id, 'customer_id' => $subscription->customer_id]);

                        continue;
                    }

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

        Cache::forever('billing.dispatch.last_run', now()->toIso8601String());
        $this->info("Dispatched {$dispatched} charge job(s).");

        if ($heldBack > 0) {
            $this->warn("{$heldBack} subscription(s) held back — too far behind to charge automatically.");
        }

        SystemLog::info('cron', "billing dispatch ran — {$dispatched} charge(s) queued", [
            'dispatched' => $dispatched,
            'held_back' => $heldBack,
            'cutoff' => $cutoff->toIso8601String(),
        ]);

        return self::SUCCESS;
    }
}
