<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Subscription;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Support\StorefrontToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A preview may start a card update — and nothing else.
 *
 * The admin preview is deliberately read-only: support has no business changing someone's
 * plan behind their back. But the one thing they are on the phone to help with WAS the one
 * thing the preview refused, so a card update is now the single permitted write.
 *
 * It is safe precisely because of what it cannot do: it opens PayMe's own page, the customer
 * types the card there, and the only possible outcome is that a blocked subscription becomes
 * billable again.
 */
class PreviewCardUpdateTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'shopify.storefront_token_secret' => 'test-storefront-secret',
            'payme.api_url' => 'https://payme.test',
            'payme.seller_id' => 'SELLER1',
        ]);

        $this->customer = Customer::query()->create([
            'email' => 'preview@example.com',
            'shopify_customer_id' => '900444',
            'phone' => '0521234567',
        ]);

        $subscription = new Subscription;
        $subscription->fill([
            'customer_id' => $this->customer->id,
            'payment_state' => PaymentState::NEEDS_CARD_UPDATE->value,
            'frequency_months' => 1,
        ]);
        $subscription->forceFill(['status' => SubscriptionStatus::ACTIVE->value])->save();
    }

    private function previewHeaders(): array
    {
        return ['Authorization' => 'Bearer '.StorefrontToken::mintPreview('900444')];
    }

    public function test_a_preview_can_start_a_card_update(): void
    {
        Http::fake(['https://payme.test/generate-sale' => Http::response([
            'status_code' => 0,
            'payme_sale_id' => 'sale_preview_1',
            'sale_url' => 'https://payme.test/hosted/sale_preview_1',
        ])]);

        $response = $this->postJson('/storefront/me/payment-method/payme/session', [], $this->previewHeaders());

        $response->assertOk();
        $this->assertNotEmpty($response->json('data.hosted_url'));
    }

    public function test_a_preview_still_cannot_change_the_subscription(): void
    {
        // The exception is one endpoint wide. Everything else stays refused, or "read-only"
        // would mean nothing at all.
        $this->patchJson('/storefront/me/address', ['address1' => 'Somewhere else'], $this->previewHeaders())
            ->assertStatus(403)
            ->assertJsonPath('reason', 'preview_read_only');
    }

    public function test_a_real_customer_token_is_unaffected(): void
    {
        Http::fake(['https://payme.test/generate-sale' => Http::response([
            'status_code' => 0,
            'payme_sale_id' => 'sale_real_1',
            'sale_url' => 'https://payme.test/hosted/sale_real_1',
        ])]);

        $this->postJson(
            '/storefront/me/payment-method/payme/session',
            [],
            ['Authorization' => 'Bearer '.StorefrontToken::mint('900444')],
        )->assertOk();
    }
}
