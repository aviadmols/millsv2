<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PaymentLedger;
use App\Models\Subscription;
use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Services\CardUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Hosted Fields card update — the v1 flow, ported: our page, our fields, the card
 * tokenised in the browser, and NOTHING charged.
 *
 * This exists because the hosted-page flow needs get-buyer-key, which this PayMe account
 * refuses ("Merchant not allowed to use this buyer" — 21 and 26 Aug), and because it put a
 * real ₪1 on a real card for every update. v1 ran for years on tokenisation instead; the
 * session envelope, the form fields and the token POST here are that flow, unchanged.
 */
class HostedFieldsCardUpdateTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'BUYER173-1234567X-XYZ';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('payme.hosted_fields_api_key', 'hf-test-key');
    }

    /** @return array{0: Customer, 1: Subscription} */
    private function scenario(): array
    {
        $customer = Customer::query()->create([
            'email' => 'hf'.uniqid().'@example.com',
            'shopify_customer_id' => (string) random_int(1000, 99999),
            'first_name' => 'Dana',
            'phone' => '0521234567',
        ]);

        $subscription = new Subscription;
        $subscription->fill([
            'customer_id' => $customer->id,
            'payment_state' => PaymentState::NEEDS_CARD_UPDATE->value,
            'frequency_months' => 1,
            'next_charge_at' => now()->addDays(5),
        ]);
        $subscription->forceFill(['status' => SubscriptionStatus::ACTIVE->value])->save();

        return [$customer, $subscription];
    }

    public function test_a_hosted_fields_session_calls_payme_not_at_all_and_charges_nothing(): void
    {
        [$customer, $subscription] = $this->scenario();
        Http::fake();

        $session = app(CardUpdateService::class)->createSession($customer, $subscription);

        $this->assertSame('hosted_fields', $session['mode']);
        $this->assertStringContainsString('/storefront/payment-method/payme-form', $session['hosted_url']);
        $this->assertStringContainsString($session['session_id'], $session['hosted_url']);

        // The whole point: no generate-sale, no verification charge, no ledger row.
        Http::assertNothingSent();
        $this->assertSame(0, PaymentLedger::query()->count());
    }

    public function test_the_form_page_renders_the_v1_fields_for_a_live_session(): void
    {
        [$customer, $subscription] = $this->scenario();
        $session = app(CardUpdateService::class)->createSession($customer, $subscription);

        $response = $this->get('/storefront/payment-method/payme-form?session_id='.$session['session_id']);

        $response->assertOk();
        $response->assertSee('מספר כרטיס', false);
        $response->assertSee('מספר תעודת זהות', false);
        $response->assertSee('hf-test-key', false);
        // Prefilled from the customer, exactly what v1 asked the payer to type.
        $response->assertSee($customer->email, false);
    }

    public function test_an_expired_link_gets_the_expiry_page_not_the_form(): void
    {
        $this->get('/storefront/payment-method/payme-form?session_id=never-existed')
            ->assertOk()
            ->assertSee('פג תוקף הקישור', false)
            ->assertDontSee('card-number', false);
    }

    public function test_posting_the_token_saves_the_card_and_lifts_the_wall(): void
    {
        [$customer, $subscription] = $this->scenario();
        $session = app(CardUpdateService::class)->createSession($customer, $subscription);

        $response = $this->postJson('/storefront/payment-method/payme-token', [
            'session_id' => $session['session_id'],
            'token' => self::TOKEN,
            'masked_card' => '•••• 4242',
        ]);

        $response->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(self::TOKEN, $customer->fresh()->activePaymentMethod()->buyer_key);
        $this->assertSame(PaymentState::PAYME, $subscription->fresh()->payment_state);
    }

    public function test_the_session_is_single_use(): void
    {
        [$customer, $subscription] = $this->scenario();
        $session = app(CardUpdateService::class)->createSession($customer, $subscription);

        $payload = ['session_id' => $session['session_id'], 'token' => self::TOKEN];

        $this->postJson('/storefront/payment-method/payme-token', $payload)->assertOk();
        // A replayed token POST must not silently re-run the update.
        $this->postJson('/storefront/payment-method/payme-token', $payload)->assertStatus(410);
    }

    public function test_a_pasted_card_number_is_never_accepted_as_a_token(): void
    {
        [$customer, $subscription] = $this->scenario();
        $session = app(CardUpdateService::class)->createSession($customer, $subscription);

        $this->postJson('/storefront/payment-method/payme-token', [
            'session_id' => $session['session_id'],
            'token' => '4580 1234 5678 9012',
        ])->assertStatus(422);

        $this->assertNull($customer->fresh()->activePaymentMethod());
        $this->assertSame(PaymentState::NEEDS_CARD_UPDATE, $subscription->fresh()->payment_state);
    }

    public function test_the_raw_token_never_reaches_the_logs(): void
    {
        [$customer, $subscription] = $this->scenario();
        $session = app(CardUpdateService::class)->createSession($customer, $subscription);

        $this->postJson('/storefront/payment-method/payme-token', [
            'session_id' => $session['session_id'],
            'token' => self::TOKEN,
        ])->assertOk();

        // The token IS the charging credential. The log row carries its fingerprint only.
        $logged = SystemLog::query()->where('message', 'card updated (hosted fields) — wall lifted')->firstOrFail();
        $this->assertStringNotContainsString(self::TOKEN, json_encode($logged->getAttributes()));
        $this->assertSame(hash('sha256', self::TOKEN), $logged->context['credential_fingerprint'] ?? null);
    }

    public function test_without_a_hosted_fields_key_the_legacy_hosted_page_flow_still_runs(): void
    {
        config()->set('payme.hosted_fields_api_key', '');
        config()->set('payme.api_url', 'https://payme.test');
        config()->set('payme.seller_id', 'SELLER1');

        [$customer, $subscription] = $this->scenario();

        Http::fake([
            'https://payme.test/generate-sale' => Http::response([
                'status_code' => 0,
                'payme_sale_id' => 'sale_legacy',
                'sale_url' => 'https://payme.test/hosted/sale_legacy',
            ]),
        ]);

        $session = app(CardUpdateService::class)->createSession($customer, $subscription);

        $this->assertSame('legacy_hosted_page', $session['mode']);
        $this->assertSame('https://payme.test/hosted/sale_legacy', $session['hosted_url']);
    }
}
