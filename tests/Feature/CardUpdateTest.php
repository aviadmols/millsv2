<?php

namespace Tests\Feature;

use App\Domain\Billing\IdempotencyKey;
use App\Domain\Billing\Ledger;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\ActivityEvent;
use App\Models\Customer;
use App\Models\PaymentLedger;
use App\Models\PaymentMethod;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\MillsSubscriptions\Enums\LedgerStatus;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Services\CardUpdateService;
use App\Modules\MillsSubscriptions\Services\PayMe\PaymeClient;
use App\Modules\MillsSubscriptions\Support\Timeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Updating a card moves real money and hands us the credential every future charge depends on.
 *
 * Three things were wrong, and each is pinned here:
 *  - the verification charge was ₪1, from a `??` buried in the HTTP client, with no ledger row
 *    and nothing refunding it — invisible money, on a real customer's card;
 *  - every update was recorded as done BY THE CUSTOMER, even when an admin did it;
 *  - the card was only stored if the customer's browser came back. Close the tab and PayMe has
 *    the money and the card while we hold nothing — silently, forever.
 */
class CardUpdateTest extends TestCase
{
    use RefreshDatabase;

    private const SALE_ID = 'sale_abc123';

    private const BUYER_KEY = 'bk_super_secret_never_render_me';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('payme.api_url', 'https://payme.test');
        config()->set('payme.seller_id', 'SELLER1');
        // The config default (100) is left in place — the tests below assert against it, so a
        // revert to a below-minimum amount would fail here rather than in production.
    }

    /** @return array{0: Customer, 1: Subscription} */
    private function scenario(PaymentState $state = PaymentState::NEEDS_CARD_UPDATE): array
    {
        $customer = Customer::query()->create([
            'email' => 'card'.uniqid().'@example.com',
            'shopify_customer_id' => (string) random_int(1000, 99999),
            'first_name' => 'Dana',
            'phone' => '0521234567',
        ]);

        $subscription = new Subscription;
        $subscription->fill([
            'customer_id' => $customer->id,
            'payment_state' => $state->value,
            'frequency_months' => 1,
            'next_charge_at' => now()->addDays(5),
        ]);
        $subscription->forceFill(['status' => SubscriptionStatus::ACTIVE->value])->save();

        return [$customer, $subscription];
    }

    private function fakePayMe(bool $withBuyerKey = true): void
    {
        Http::fake([
            'https://payme.test/generate-sale' => Http::response([
                'status_code' => 0,
                'payme_sale_id' => self::SALE_ID,
                'sale_url' => 'https://payme.test/hosted/'.self::SALE_ID,
            ]),
            'https://payme.test/get-buyer-key' => Http::response(
                $withBuyerKey
                    ? ['status_code' => 0, 'buyer_key' => self::BUYER_KEY, 'masked_card' => '**** 4242']
                    : ['status_code' => 0],
            ),
        ]);
    }

    // --- the money ------------------------------------------------------------

    public function test_the_verification_charge_is_the_accounts_minimum(): void
    {
        [$customer, $subscription] = $this->scenario();
        $this->fakePayMe();

        app(CardUpdateService::class)->createSession($customer, $subscription);

        Http::assertSent(function ($request) {
            // 100 agorot (₪1), the smallest amount the live PayMe account accepts. ₪0.10 was
            // rejected with error 352 "סכום העסקה חורג מהמגבלות" and every card update failed.
            return str_contains($request->url(), 'generate-sale')
                && $request['sale_price'] === 100;
        });
    }

    public function test_the_amount_is_configurable_for_the_day_payme_allows_less(): void
    {
        config()->set('payme.card_update_verification_agorot', 25);

        [$customer, $subscription] = $this->scenario();
        $this->fakePayMe();

        app(CardUpdateService::class)->createSession($customer, $subscription);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'generate-sale')
            && $request['sale_price'] === 25);
    }

    public function test_a_price_is_never_assumed(): void
    {
        $this->expectExceptionMessage('card_update_price_missing');

        app(PaymeClient::class)
            ->createBuyerCaptureSale(['callbackUrl' => 'https://example.test/cb']);
    }

    public function test_the_verification_charge_is_recorded_in_the_ledger(): void
    {
        [$customer, $subscription] = $this->scenario();
        $this->fakePayMe();

        $session = app(CardUpdateService::class)->createSession($customer, $subscription);

        $ledger = Ledger::find(IdempotencyKey::cardUpdate($session['session_id']));

        // Money that moves without a ledger row is money the system cannot account for.
        $this->assertNotNull($ledger);
        $this->assertSame(IdempotencyKey::CONTEXT_CARD_UPDATE, $ledger->context);
        $this->assertSame('1.00', (string) $ledger->amount);
        $this->assertSame(LedgerStatus::PENDING, $ledger->status);
        $this->assertSame(self::SALE_ID, $ledger->payme_transaction_id);
    }

    public function test_a_zero_verification_charge_opens_no_ledger_row(): void
    {
        // The end state: PayMe enables zero-amount tokenisation, the charge disappears, and
        // so does the row that accounted for it. Nothing else in the flow changes.
        config()->set('payme.card_update_verification_agorot', 0);

        [$customer, $subscription] = $this->scenario();
        $this->fakePayMe();

        app(CardUpdateService::class)->createSession($customer, $subscription);

        $this->assertSame(0, PaymentLedger::query()->count());
    }

    // --- who did it ------------------------------------------------------------

    public function test_an_admin_card_update_is_recorded_as_the_admin(): void
    {
        [$customer, $subscription] = $this->scenario();
        $this->fakePayMe();

        $service = app(CardUpdateService::class);
        $session = $service->createSession($customer, $subscription, Timeline::admin(7));
        $service->consume($session['session_id']);

        $event = ActivityEvent::query()->where('kind', Timeline::KIND_CARD_UPDATED)->firstOrFail();

        // "The customer updated their own card" is a lie when a staff member typed it in, and
        // the audit feed is the only thing that can answer who touched someone's card.
        $this->assertSame('admin:7', $event->actor);
    }

    public function test_a_customer_card_update_is_still_recorded_as_the_customer(): void
    {
        [$customer, $subscription] = $this->scenario();
        $this->fakePayMe();

        $service = app(CardUpdateService::class);
        $session = $service->createSession($customer, $subscription);   // storefront: no actor
        $service->consume($session['session_id']);

        $event = ActivityEvent::query()->where('kind', Timeline::KIND_CARD_UPDATED)->firstOrFail();

        $this->assertSame(Timeline::ACTOR_CUSTOMER, $event->actor);
    }

    // --- the wall ------------------------------------------------------------

    public function test_one_card_unblocks_every_subscription_of_the_customer(): void
    {
        [$customer, $subscription] = $this->scenario();

        $sibling = new Subscription;
        $sibling->fill([
            'customer_id' => $customer->id,
            'payment_state' => PaymentState::NEEDS_CARD_UPDATE->value,
            'frequency_months' => 1,
        ]);
        $sibling->forceFill(['status' => SubscriptionStatus::ACTIVE->value])->save();

        $this->fakePayMe();

        $service = app(CardUpdateService::class);
        $session = $service->createSession($customer, $subscription);
        $service->consume($session['session_id']);

        // One card backs them all — leaving a sibling blocked would silently stop billing a
        // customer who has just given us a working card.
        $this->assertSame(PaymentState::PAYME, $subscription->fresh()->payment_state);
        $this->assertSame(PaymentState::PAYME, $sibling->fresh()->payment_state);

        $card = $customer->fresh()->activePaymentMethod();
        $this->assertNotNull($card);
        $this->assertSame('**** 4242', $card->masked_card);
    }

    public function test_the_ledger_row_is_settled_once_the_card_is_stored(): void
    {
        [$customer, $subscription] = $this->scenario();
        $this->fakePayMe();

        $service = app(CardUpdateService::class);
        $session = $service->createSession($customer, $subscription);
        $service->consume($session['session_id']);

        $ledger = Ledger::find(IdempotencyKey::cardUpdate($session['session_id']));

        $this->assertSame(LedgerStatus::SUCCEEDED, $ledger->status);
        $this->assertNotNull($ledger->executed_at);
    }

    // --- the closed tab ------------------------------------------------------

    public function test_a_link_opened_after_the_session_expired_still_saves_the_card(): void
    {
        [$customer, $subscription] = $this->scenario();
        $this->fakePayMe();

        $service = app(CardUpdateService::class);
        $session = $service->createSession($customer, $subscription);

        // An SMS'd link is opened an hour later: the 15-minute cache is long gone.
        Cache::flush();

        $service->consume($session['session_id']);

        // Without the ledger fallback the customer would be charged, hand over a card, and be
        // told the link had expired — while we threw the token away.
        $this->assertNotNull($customer->fresh()->activePaymentMethod());
        $this->assertSame(PaymentState::PAYME, $subscription->fresh()->payment_state);
    }

    public function test_a_card_captured_but_never_returned_to_us_is_recovered(): void
    {
        [$customer, $subscription] = $this->scenario();
        $this->fakePayMe();

        $session = app(CardUpdateService::class)->createSession($customer, $subscription);

        // The customer entered the card and closed the tab. The callback never ran.
        Cache::flush();
        PaymentLedger::query()->update(['created_at' => now()->subHour()]);

        $this->assertNull($customer->fresh()->activePaymentMethod());

        $this->artisan('mills:reconcile-card-updates')->assertExitCode(0);

        // PayMe took the money and captured the card. Somebody has to go and look.
        $this->assertNotNull($customer->fresh()->activePaymentMethod());
        $this->assertSame(PaymentState::PAYME, $subscription->fresh()->payment_state);

        $ledger = Ledger::find(IdempotencyKey::cardUpdate($session['session_id']));
        $this->assertSame(LedgerStatus::SUCCEEDED, $ledger->status);

        $event = ActivityEvent::query()->where('kind', Timeline::KIND_CARD_UPDATED)->firstOrFail();
        $this->assertSame(Timeline::ACTOR_SYSTEM, $event->actor);
        $this->assertTrue($event->details['recovered_by_reconciliation']);
    }

    public function test_an_abandoned_card_update_is_closed_and_never_lifts_the_wall(): void
    {
        [$customer, $subscription] = $this->scenario();
        $this->fakePayMe(withBuyerKey: false);   // the shopper never entered a card

        $session = app(CardUpdateService::class)->createSession($customer, $subscription);

        Cache::flush();
        PaymentLedger::query()->update(['created_at' => now()->subHour()]);

        $this->artisan('mills:reconcile-card-updates')->assertExitCode(0);

        $this->assertNull($customer->fresh()->activePaymentMethod());
        $this->assertSame(PaymentState::NEEDS_CARD_UPDATE, $subscription->fresh()->payment_state);

        $ledger = Ledger::find(IdempotencyKey::cardUpdate($session['session_id']));
        $this->assertSame(LedgerStatus::FAILED, $ledger->status);
        $this->assertSame('abandoned', $ledger->failure_code);
    }

    /**
     * The same abandonment, in the shape PayMe actually answers with.
     *
     * The test above fakes a clean 200 carrying no buyer_key. PayMe does not do that: it
     * reports business outcomes as HTTP 500 with status_error_code 600, "Buyer not found".
     * PaymeClient::post() throws on any non-2xx, the reconciler caught the throw as "PayMe
     * unreachable", and the row stayed pending — for five real sessions, until each one
     * showed up on the dashboard as a charge in an unknown state that no command could clear.
     */
    public function test_payme_answering_buyer_not_found_over_http_500_closes_the_row(): void
    {
        [$customer, $subscription] = $this->scenario();

        Http::fake([
            'https://payme.test/generate-sale' => Http::response([
                'status_code' => 0,
                'payme_sale_id' => self::SALE_ID,
                'sale_url' => 'https://payme.test/hosted/'.self::SALE_ID,
            ]),
            'https://payme.test/get-buyer-key' => Http::response([
                'status_code' => 1,
                'status_error_code' => 600,
                'status_error_details' => 'Buyer not found',
            ], 500),
        ]);

        $session = app(CardUpdateService::class)->createSession($customer, $subscription);

        Cache::flush();
        PaymentLedger::query()->update(['created_at' => now()->subHour()]);

        $this->artisan('mills:reconcile-card-updates')->assertExitCode(0);

        $ledger = Ledger::find(IdempotencyKey::cardUpdate($session['session_id']));
        $this->assertSame(LedgerStatus::FAILED, $ledger->status);
        $this->assertSame('abandoned', $ledger->failure_code);

        // No card was captured, so the wall stays exactly where it was.
        $this->assertNull($customer->fresh()->activePaymentMethod());
        $this->assertSame(PaymentState::NEEDS_CARD_UPDATE, $subscription->fresh()->payment_state);
    }

    /** A card update PayMe cannot answer for stays pending — but it says so out loud. */
    public function test_an_unresolvable_card_update_is_logged_not_just_left_pending(): void
    {
        [$customer, $subscription] = $this->scenario();

        Http::fake([
            'https://payme.test/generate-sale' => Http::response([
                'status_code' => 0,
                'payme_sale_id' => self::SALE_ID,
                'sale_url' => 'https://payme.test/hosted/'.self::SALE_ID,
            ]),
            // Not a business answer — PayMe itself is unwell.
            'https://payme.test/get-buyer-key' => Http::response(['status_code' => 1], 503),
        ]);

        $session = app(CardUpdateService::class)->createSession($customer, $subscription);

        Cache::flush();
        PaymentLedger::query()->update(['created_at' => now()->subHour()]);

        $this->artisan('mills:reconcile-card-updates')->assertExitCode(0);

        // Never guess about a card: the row waits for the next pass.
        $ledger = Ledger::find(IdempotencyKey::cardUpdate($session['session_id']));
        $this->assertSame(LedgerStatus::PENDING, $ledger->status);

        $this->assertDatabaseHas('system_logs', [
            'level' => 'error',
            'message' => 'a card update could not be resolved and stays pending',
        ]);
    }

    // --- the poison pill -----------------------------------------------------

    public function test_the_billing_reconciler_leaves_card_update_rows_alone(): void
    {
        [$customer, $subscription] = $this->scenario();
        $this->fakePayMe();

        $session = app(CardUpdateService::class)->createSession($customer, $subscription);

        PaymentLedger::query()->update(['created_at' => now()->subHour()]);

        $this->artisan('mills:reconcile-payments')->assertExitCode(0);

        // mills:reconcile-payments resolves rows as SUBSCRIPTION CHARGES. Let it near a
        // card-update row and it looks it up, finds no charge, marks it failed and schedules
        // a billing backoff on the subscription — retries for a charge nobody ever attempted.
        $ledger = Ledger::find(IdempotencyKey::cardUpdate($session['session_id']));
        $this->assertSame(LedgerStatus::PENDING, $ledger->status);
        $this->assertSame(0, (int) $subscription->fresh()->attempt_count);
    }

    // --- what may be shown ---------------------------------------------------

    public function test_the_buyer_key_is_never_rendered_on_the_subscription_screen(): void
    {
        [$customer, $subscription] = $this->scenario(PaymentState::PAYME);

        PaymentMethod::query()->create([
            'customer_id' => $customer->id,
            'gateway' => 'payme',
            'buyer_key' => self::BUYER_KEY,
            'masked_card' => '**** 4242',
            'is_active' => true,
            'source' => 'card_update',
            'captured_at' => now(),
        ]);

        $this->actingAs(User::factory()->create());

        $response = $this->get(
            SubscriptionResource::getUrl('view', ['record' => $subscription])
        );

        $response->assertOk();
        $response->assertSee('**** 4242');
        // The masked number is the only part of a card fit to put on a screen.
        $response->assertDontSee(self::BUYER_KEY);
    }

    public function test_merely_looking_at_the_subscription_screen_never_charges_the_customer(): void
    {
        [, $subscription] = $this->scenario();

        Http::fake();

        $this->actingAs(User::factory()->create());

        $this->get(
            SubscriptionResource::getUrl('view', ['record' => $subscription])
        )->assertOk();

        // The session is minted when the modal OPENS. Building it on render would put a
        // charge on the customer's card every time an admin glanced at this page.
        Http::assertNothingSent();
    }

    // --- PayMe refusing the buyer_key ----------------------------------------

    /**
     * The 21 Aug incident: the customer entered a real card, PayMe answered get-buyer-key
     * with HTTP 500 "Merchant not allowed to use this buyer", and because RequestException
     * is not a RuntimeException the callback's catch missed it — the customer got Laravel's
     * raw 500 page one second after typing their card number.
     */
    public function test_payme_refusing_the_buyer_key_shows_a_page_not_a_500(): void
    {
        [$customer, $subscription] = $this->scenario();

        Http::fake([
            'https://payme.test/generate-sale' => Http::response([
                'status_code' => 0,
                'payme_sale_id' => self::SALE_ID,
                'sale_url' => 'https://payme.test/hosted/'.self::SALE_ID,
            ]),
            'https://payme.test/get-buyer-key' => Http::response([
                'status_code' => 1,
                'status_error_details' => 'Merchant not allowed to use this buyer',
            ], 500),
        ]);

        $session = app(CardUpdateService::class)->createSession($customer, $subscription);

        $response = $this->get('/storefront/payment-method/payme-callback?session_id='.$session['session_id']);

        // A page, in Hebrew, that does NOT tell them to re-enter a card PayMe already holds.
        $response->assertOk();
        $response->assertSee('אין צורך להזין את הכרטיס שוב', false);

        // The refusal is on the admin's screen with PayMe's own words — not only in Railway logs.
        $this->assertDatabaseHas('system_logs', [
            'level' => 'error',
            'message' => 'card update failed — PayMe refused get-buyer-key',
        ]);

        // The row stays PENDING: the card may be captured at PayMe, and closing the row
        // would throw away the only trace the reconciler can recover it from.
        $ledger = Ledger::find(IdempotencyKey::cardUpdate($session['session_id']));
        $this->assertSame(LedgerStatus::PENDING, $ledger->status);
        $this->assertSame(PaymentState::NEEDS_CARD_UPDATE, $subscription->fresh()->payment_state);
    }

    public function test_a_refused_card_update_can_be_completed_later_without_the_customer(): void
    {
        [$customer, $subscription] = $this->scenario();

        Http::fake([
            'https://payme.test/generate-sale' => Http::response([
                'status_code' => 0,
                'payme_sale_id' => self::SALE_ID,
                'sale_url' => 'https://payme.test/hosted/'.self::SALE_ID,
            ]),
            'https://payme.test/get-buyer-key' => Http::sequence()
                ->push(['status_code' => 1, 'status_error_details' => 'Merchant not allowed to use this buyer'], 500)
                ->push(['status_code' => 0, 'buyer_key' => self::BUYER_KEY, 'masked_card' => '**** 4242']),
        ]);

        $service = app(CardUpdateService::class);
        $session = $service->createSession($customer, $subscription);

        // First attempt: PayMe refuses. The customer sees the graceful page and leaves.
        $this->get('/storefront/payment-method/payme-callback?session_id='.$session['session_id'])->assertOk();

        // Once PayMe is fixed, the SAME session resolves from the ledger row — the admin
        // retry (or the reconciler) finishes the update with no new card entry and no new charge.
        $result = $service->consume($session['session_id']);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['subscriptions_unblocked']);
        $this->assertSame(PaymentState::PAYME, $subscription->fresh()->payment_state);
        $this->assertSame(self::BUYER_KEY, $customer->fresh()->activePaymentMethod()->buyer_key);
        $this->assertSame(LedgerStatus::SUCCEEDED, Ledger::find(IdempotencyKey::cardUpdate($session['session_id']))->status);
    }
}
