<?php

namespace App\Jobs;

use App\Models\Subscription;
use App\Modules\MillsSubscriptions\Services\ChargeOrchestrator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One charge attempt for one subscription (ARCHITECTURE.md §5, CLAUDE.md law #9).
 * ShouldBeUnique per subscription is the concurrency guard; tries=1 because
 * retries are domain-scheduled (next_retry_at backoff), never queue-level.
 */
class ChargeSubscriptionJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct(
        public int $subscriptionId,
        public ?string $idempotencyKey = null,
    ) {
        $this->onQueue('charges');
    }

    public function uniqueId(): string
    {
        // Unique per (subscription, cycle key) so a re-dispatch for the same cycle
        // collapses, while the next cycle is a distinct job.
        return 'charge:subscription:'.$this->subscriptionId.':'.($this->idempotencyKey ?? 'auto');
    }

    public function handle(ChargeOrchestrator $orchestrator): void
    {
        $subscription = Subscription::query()->find($this->subscriptionId);
        if ($subscription !== null) {
            $orchestrator->charge($subscription, idempotencyKey: $this->idempotencyKey);
        }
    }
}
