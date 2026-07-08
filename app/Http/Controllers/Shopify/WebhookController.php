<?php

namespace App\Http\Controllers\Shopify;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessShopifyWebhookJob;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Single webhook receiver (ARCHITECTURE.md §1b). HMAC is already verified by the
 * shopify.webhook middleware. Dedupes by Shopify's webhook id, persists the raw
 * payload, and processes asynchronously — responds 202 fast so Shopify doesn't
 * retry on our processing time.
 */
class WebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $topic = (string) $request->header('X-Shopify-Topic', '');
        $webhookId = (string) $request->header('X-Shopify-Webhook-Id', '');

        $event = WebhookEvent::query()->firstOrCreate(
            ['webhook_id' => $webhookId !== '' ? $webhookId : null],
            ['topic' => $topic, 'payload' => $request->json()->all(), 'status' => 'received'],
        );

        if ($event->wasRecentlyCreated) {
            ProcessShopifyWebhookJob::dispatch($event->id);
        }

        return response()->json(['ok' => true], 202);
    }
}
