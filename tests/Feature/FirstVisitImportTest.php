<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Subscription;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Services\Shopify\ShopifyCustomerService;
use App\Support\StorefrontToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A Shopify-logged-in customer opens their personal area for the first time.
 *
 * The theme mints their token server-side, so the identity is proven — but the middleware
 * required a LOCAL customer row and answered 401 `customer_not_found` to anyone we had never
 * imported. That is most of the store: the SMS login imported people on entry, while the
 * ordinary /account path threw the same people out with a blank screen.
 */
class FirstVisitImportTest extends TestCase
{
    use RefreshDatabase;

    private const NOTE = '{"discount":0.9,"interval":1,"status":"account-active","dogs":[{"status":"active","quizData":{"allergy":[],"age":8,"weight":3,"activity":0,"body":1},"name":"כלב 1","sex":0,"avatar":1,"caloriesPerDay":191,"variants":[{"id":39357390782621,"grams":1530,"price":171}]}],"nextDelivery":"2026-06-18"}';

    protected function setUp(): void
    {
        parent::setUp();

        config(['shopify.storefront_token_secret' => 'test-storefront-secret']);
    }

    /** Shopify holding the given customers, keyed by id. */
    private function fakeShopify(array $customers): void
    {
        $this->app->instance(ShopifyCustomerService::class, new class($customers) extends ShopifyCustomerService
        {
            public function __construct(private array $customers) {}

            public function search(string $term, int $limit = 20): array
            {
                return array_values($this->customers);
            }

            public function find(string $idOrGid): array
            {
                return $this->customers[(string) $idOrGid] ?? [];
            }
        });
    }

    /** @return array<string, mixed> */
    private function shopifyCustomer(string $id, string $note = ''): array
    {
        return [
            'id' => $id,
            'email' => "first{$id}@example.com",
            'phone' => '+972521230000',
            'first_name' => 'Rina',
            'last_name' => 'Cohen',
            'note' => $note,
            'default_address' => ['address1' => 'Herzl 1', 'city' => 'Tel Aviv'],
        ];
    }

    public function test_a_first_time_visitor_is_imported_and_let_in(): void
    {
        $this->fakeShopify(['900777' => $this->shopifyCustomer('900777')]);

        $this->assertSame(0, Customer::query()->count());

        $response = $this->getJson('/storefront/me', [
            'Authorization' => 'Bearer '.StorefrontToken::mint('900777'),
        ]);

        // The whole point: no local row, a proven identity, and the door opens.
        $response->assertOk()->assertJsonPath('ok', true);
        $this->assertSame('first900777@example.com', Customer::query()->firstOrFail()->email);
    }

    public function test_their_legacy_subscription_comes_with_them(): void
    {
        $this->fakeShopify(['900777' => $this->shopifyCustomer('900777', self::NOTE)]);

        $response = $this->getJson('/storefront/me', [
            'Authorization' => 'Bearer '.StorefrontToken::mint('900777'),
        ]);

        $response->assertOk();
        // The payload they see on this very first request already carries the subscription
        // from their note — flagged for a card update, so the banner shows immediately.
        $response->assertJsonPath('data.subscriptions.0.requires_card_update', true);

        $this->assertSame(
            PaymentState::NEEDS_CARD_UPDATE,
            Subscription::query()->firstOrFail()->payment_state,
        );
    }

    public function test_a_subject_shopify_does_not_know_is_still_refused(): void
    {
        $this->fakeShopify([]);

        // A validly-signed token whose subject does not exist anywhere is not a shopper — it
        // is a forged or stale id, and inventing a customer for it would be worse.
        $this->getJson('/storefront/me', [
            'Authorization' => 'Bearer '.StorefrontToken::mint('999999'),
        ])->assertStatus(401)->assertJsonPath('reason', 'customer_not_found');

        $this->assertSame(0, Customer::query()->count());
    }

    public function test_a_preview_token_never_imports(): void
    {
        $this->fakeShopify(['900777' => $this->shopifyCustomer('900777')]);

        // Read-only means read-only: an admin previewing an id we do not hold must not cause
        // rows to be written as a side effect of looking.
        $this->getJson('/storefront/me', [
            'Authorization' => 'Bearer '.StorefrontToken::mintPreview('900777'),
        ])->assertStatus(401);

        $this->assertSame(0, Customer::query()->count());
    }

    public function test_an_existing_customer_is_not_touched(): void
    {
        $this->fakeShopify([]);   // Shopify unreachable/empty — must not matter

        Customer::query()->create([
            'email' => 'existing@example.com',
            'shopify_customer_id' => '900777',
        ]);

        $this->getJson('/storefront/me', [
            'Authorization' => 'Bearer '.StorefrontToken::mint('900777'),
        ])->assertOk();

        $this->assertSame(1, Customer::query()->count());
    }
}
