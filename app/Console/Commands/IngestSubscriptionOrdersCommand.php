<?php

namespace App\Console\Commands;

use App\Models\WebhookEvent;
use App\Modules\MillsSubscriptions\Services\PaidOrderIngestor;
use Illuminate\Console\Command;

/**
 * Backfill: re-read stored orders/paid webhooks and ingest the subscription
 * signups among them. Exists because before 2026-08-04 the webhook job dropped
 * orders/paid as "unhandled" — the payloads were kept, so nothing was lost.
 * Safe to run any number of times: ingest() keys on the order id.
 */
class IngestSubscriptionOrdersCommand extends Command
{
    protected $signature = 'mills:ingest-subscription-orders {--days=90 : How far back to scan}';

    protected $description = 'Create subscriptions from stored orders/paid webhooks that were missed.';

    public function handle(PaidOrderIngestor $ingestor): int
    {
        $scanned = 0;
        $created = 0;

        WebhookEvent::query()
            ->where('topic', 'orders/paid')
            ->where('created_at', '>=', now()->subDays((int) $this->option('days')))
            ->orderBy('id')
            ->chunkById(50, function ($events) use ($ingestor, &$scanned, &$created) {
                foreach ($events as $event) {
                    $scanned++;
                    $subscription = $ingestor->ingest((array) ($event->payload ?? []));

                    if ($subscription?->wasRecentlyCreated) {
                        $created++;
                        $this->info(sprintf(
                            'Subscription #%d created from order %s',
                            $subscription->id,
                            (string) data_get($event->payload, 'name', data_get($event->payload, 'id')),
                        ));
                    }
                }
            });

        $this->info("Scanned {$scanned} paid-order webhook(s); created {$created} subscription(s).");

        return self::SUCCESS;
    }
}
