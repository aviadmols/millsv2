<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Dog;
use App\Models\QuizDog;
use App\Models\Subscription;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves the machine-to-machine contract (SYSTEM-MAP §3.1/§3.2): the API secret
 * guards it, the theme's quiz endpoint works, and — critically — the LEGACY
 * NestJS paths the existing theme already calls (/shopify/subscription/*,
 * /shopify/dog/*, /order/*) hit the same controllers as their /api twins.
 */
class ApiContractTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-api-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['api.secret' => self::SECRET]);
    }

    private function auth(): array
    {
        return ['X-API-Secret' => self::SECRET];
    }

    private function seedData(): array
    {
        $customer = Customer::query()->create([
            'shopify_customer_id' => '5001',
            'email' => 'api@example.com',
            'first_name' => 'Api',
            'last_name' => 'Tester',
        ]);

        $subscription = new Subscription;
        $subscription->fill([
            'customer_id' => $customer->id,
            'payment_state' => PaymentState::PAYME->value,
            'frequency_months' => 1,
            'next_charge_at' => now()->toDateString(),
        ]);
        $subscription->forceFill(['status' => SubscriptionStatus::ACTIVE->value])->save();

        $dog = Dog::query()->create([
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'name' => 'Bingo',
            'status' => 'active',
            'selected_variants' => ['v-1'],
            'addons_products' => [],
        ]);

        return [$customer, $subscription, $dog];
    }

    public function test_the_api_secret_is_required(): void
    {
        $this->getJson('/api/subscriptions')->assertStatus(401);
        $this->getJson('/api/subscriptions', $this->auth())->assertOk();
    }

    public function test_the_theme_quiz_endpoint_stores_answers_and_returns_an_id(): void
    {
        $response = $this->postJson('/api/dogs/quiz', [
            'name' => 'Rex',
            'weight' => 12,
            'variants' => ['v-9'],
        ], $this->auth());

        $response->assertOk()->assertJsonStructure(['id']);

        $this->assertDatabaseCount('quiz_dogs', 1);
        $this->assertSame('Rex', QuizDog::query()->firstOrFail()->payload['name']);
    }

    public function test_the_legacy_quiz_path_hits_the_same_controller(): void
    {
        $this->postJson('/shopify/dog/save-quiz-dog', ['name' => 'Legacy'], $this->auth())
            ->assertOk()
            ->assertJsonStructure(['id']);

        $this->assertSame('Legacy', QuizDog::query()->firstOrFail()->payload['name']);
    }

    public function test_due_today_lists_billable_subscriptions_only(): void
    {
        [$customer, $subscription] = $this->seedData();

        // A subscription still awaiting a card is never billable.
        $blocked = new Subscription;
        $blocked->fill([
            'customer_id' => $customer->id,
            'payment_state' => PaymentState::NEEDS_CARD_UPDATE->value,
            'frequency_months' => 1,
            'next_charge_at' => now()->toDateString(),
        ]);
        $blocked->forceFill(['status' => SubscriptionStatus::ACTIVE->value])->save();

        $response = $this->getJson('/api/subscriptions/due-today', $this->auth())->assertOk();

        $ids = array_column($response->json(), 'numeric_id');
        $this->assertContains((string) $subscription->id, $ids);
        $this->assertNotContains((string) $blocked->id, $ids);
    }

    public function test_legacy_due_today_alias_returns_the_same_thing(): void
    {
        [, $subscription] = $this->seedData();

        $response = $this->getJson('/shopify/subscription/active/charge-cycle-today', $this->auth())->assertOk();

        $this->assertSame([(string) $subscription->id], array_column($response->json(), 'numeric_id'));
    }

    public function test_products_map_is_keyed_by_dog(): void
    {
        [, $subscription, $dog] = $this->seedData();

        $this->getJson("/api/subscriptions/{$subscription->id}/products", $this->auth())
            ->assertOk()
            ->assertJsonPath("dogs.{$dog->id}.subscription_products", ['v-1'])
            ->assertJsonPath("dogs.{$dog->id}.addons_products", []);
    }

    public function test_legacy_collection_patch_takes_the_id_from_the_query_string(): void
    {
        [, $subscription] = $this->seedData();

        // The v1 quirk: PATCH /shopify/subscription/?id=<id>
        $this->patchJson("/shopify/subscription?id={$subscription->id}", [
            'frequency' => 'Every 2 Months',
        ], $this->auth())
            ->assertOk()
            ->assertJsonPath('frequency', 'Every 2 Months');

        $this->assertSame(2, (int) $subscription->fresh()->frequency_months);

        // …and without an id it is a 422, not a silent no-op.
        $this->patchJson('/shopify/subscription', ['frequency' => 'Monthly'], $this->auth())
            ->assertStatus(422);
    }

    public function test_a_subscription_cannot_be_activated_with_no_dogs(): void
    {
        $customer = Customer::query()->create(['shopify_customer_id' => '6001', 'email' => 'nodogs@x.co']);

        $subscription = new Subscription;
        $subscription->fill(['customer_id' => $customer->id, 'payment_state' => PaymentState::PAYME->value, 'frequency_months' => 1]);
        $subscription->forceFill(['status' => SubscriptionStatus::PENDING->value])->save();

        $this->patchJson("/api/subscriptions/{$subscription->id}", [
            'subscription_status' => 'active',
        ], $this->auth())->assertStatus(422);

        $this->assertSame(SubscriptionStatus::PENDING, $subscription->fresh()->status);
    }

    public function test_delete_cancels_immediately_and_clears_the_next_charge(): void
    {
        [, $subscription] = $this->seedData();

        $this->deleteJson("/api/subscriptions/{$subscription->id}", [], $this->auth())
            ->assertOk()
            ->assertJsonStructure(['deletedId']);

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::CANCELLED, $subscription->status);
        $this->assertNull($subscription->next_charge_at);
    }

    public function test_cron_status_reports_the_billing_switch(): void
    {
        $this->getJson('/api/cron/status', $this->auth())
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('isRunning', true);

        $this->postJson('/api/cron/stop', [], $this->auth())->assertOk();

        $this->getJson('/api/cron/status', $this->auth())
            ->assertOk()
            ->assertJsonPath('isRunning', false);

        // The legacy alias controls the same switch.
        $this->postJson('/order/cron/start', [], $this->auth())->assertOk();
        $this->getJson('/order/cron/status', $this->auth())
            ->assertOk()
            ->assertJsonPath('isRunning', true);
    }
}
