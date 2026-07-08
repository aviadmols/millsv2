<?php

namespace Tests\Feature;

use App\Jobs\ProcessShopifyWebhookJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Proves the fail-closed webhook HMAC gate (ARCHITECTURE.md §1b) and dedupe.
 */
class ShopifyWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shopify.webhook_secret' => 'wh-secret']);
        Queue::fake();
    }

    private function sendWebhook(string $payload, array $headers): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $k => $v) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }

        return $this->call('POST', '/shopify/webhooks', [], [], [], $server, $payload);
    }

    public function test_valid_hmac_is_accepted_stored_and_dispatched(): void
    {
        $payload = json_encode(['id' => 123, 'title' => 'Test product']);
        $hmac = base64_encode(hash_hmac('sha256', $payload, 'wh-secret', true));

        $this->sendWebhook($payload, [
            'X-Shopify-Hmac-Sha256' => $hmac,
            'X-Shopify-Topic' => 'products/update',
            'X-Shopify-Webhook-Id' => 'wh-1',
        ])->assertStatus(202)->assertJson(['ok' => true]);

        $this->assertDatabaseHas('webhook_events', ['webhook_id' => 'wh-1', 'topic' => 'products/update']);
        Queue::assertPushed(ProcessShopifyWebhookJob::class);
    }

    public function test_invalid_hmac_is_rejected(): void
    {
        $payload = json_encode(['id' => 1]);

        $this->sendWebhook($payload, [
            'X-Shopify-Hmac-Sha256' => base64_encode('the-wrong-signature'),
            'X-Shopify-Topic' => 'products/update',
        ])->assertStatus(401);

        $this->assertDatabaseCount('webhook_events', 0);
        Queue::assertNothingPushed();
    }

    public function test_duplicate_webhook_id_is_not_reprocessed(): void
    {
        $payload = json_encode(['id' => 5]);
        $hmac = base64_encode(hash_hmac('sha256', $payload, 'wh-secret', true));
        $headers = [
            'X-Shopify-Hmac-Sha256' => $hmac,
            'X-Shopify-Topic' => 'products/update',
            'X-Shopify-Webhook-Id' => 'dup-1',
        ];

        $this->sendWebhook($payload, $headers)->assertStatus(202);
        $this->sendWebhook($payload, $headers)->assertStatus(202);

        $this->assertDatabaseCount('webhook_events', 1);
        Queue::assertPushed(ProcessShopifyWebhookJob::class, 1);
    }
}
