<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use App\Modules\MillsSubscriptions\Services\Shopify\ProductSyncService;
use App\Modules\MillsSubscriptions\Services\Shopify\ShopInstaller;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Routes a stored webhook to its handler (ARCHITECTURE.md §1b). Orders topics are
 * wired in Phase 4/5 (order sync + billing reconciliation); products/* refresh the
 * cache; app/uninstalled tears the connection down.
 */
class ProcessShopifyWebhookJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $webhookEventId)
    {
        $this->onQueue('sync');
    }

    public function handle(ProductSyncService $products, ShopInstaller $installer): void
    {
        $event = WebhookEvent::query()->find($this->webhookEventId);
        if ($event === null || $event->processed_at !== null) {
            return;
        }

        try {
            match (true) {
                $event->topic === 'app/uninstalled' => $installer->markUninstalled(),
                str_starts_with((string) $event->topic, 'products/') => $this->handleProduct($event, $products),
                default => Log::info('shopify.webhook.unhandled', ['topic' => $event->topic]),
            };

            $event->forceFill(['status' => 'processed', 'processed_at' => now()])->save();
        } catch (Throwable $e) {
            $event->forceFill(['status' => 'failed', 'error_message' => $e->getMessage()])->save();
            throw $e;
        }
    }

    private function handleProduct(WebhookEvent $event, ProductSyncService $products): void
    {
        // The payload is a single product (REST shape) — re-fetch canonical data.
        $payload = (array) ($event->payload ?? []);
        if (($event->topic === 'products/delete')) {
            // Soft-unlist handled on next full refresh; nothing destructive here.
            return;
        }

        if (isset($payload['id'])) {
            $products->refreshAll(); // Phase 3 refines to a single-product fetch.
        }
    }
}
