<?php

namespace App\Console\Commands;

use App\Domain\Billing\IdempotencyKey;
use App\Http\Controllers\Api\CronApiController;
use App\Jobs\ChargeSubscriptionJob;
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

        SystemLog::info('cron', "billing dispatch ran — {$dispatched} charge(s) queued", [
            'dispatched' => $dispatched,
            'cutoff' => $cutoff->toIso8601String(),
        ]);

        return self::SUCCESS;
    }
}
