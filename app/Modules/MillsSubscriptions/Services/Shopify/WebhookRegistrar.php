<?php

namespace App\Modules\MillsSubscriptions\Services\Shopify;

use Illuminate\Support\Facades\Log;

/**
 * Programmatically subscribes the app's webhook topics to the single receive
 * endpoint (ARCHITECTURE.md §1b). Idempotent — Shopify de-dupes an identical
 * (topic, callbackUrl) subscription.
 */
class WebhookRegistrar
{
    private const MUTATION = <<<'GQL'
    mutation webhookSubscriptionCreate($topic: WebhookSubscriptionTopic!, $sub: WebhookSubscriptionInput!) {
      webhookSubscriptionCreate(topic: $topic, webhookSubscription: $sub) {
        userErrors { field message }
        webhookSubscription { id }
      }
    }
    GQL;

    public function __construct(private readonly ShopifyAdminClient $client) {}

    public function registerAll(): void
    {
        if (! $this->client->isConnected()) {
            Log::warning('shopify.webhooks.not_connected');

            return;
        }

        $address = (string) config('shopify.webhook_address');

        foreach ((array) config('shopify.webhook_topics', []) as $topic) {
            $result = $this->client->graphql(self::MUTATION, [
                'topic' => $this->toEnum((string) $topic),
                'sub' => ['callbackUrl' => $address, 'format' => 'JSON'],
            ]);

            $errors = $result['data']['webhookSubscriptionCreate']['userErrors'] ?? [];
            if ($errors !== []) {
                Log::warning('shopify.webhooks.register_error', ['topic' => $topic, 'errors' => $errors]);
            }
        }
    }

    /** orders/paid -> ORDERS_PAID */
    private function toEnum(string $topic): string
    {
        return strtoupper(str_replace('/', '_', $topic));
    }
}
