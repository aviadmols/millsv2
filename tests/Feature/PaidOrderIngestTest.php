<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Dog;
use App\Models\Product;
use App\Models\QuizDog;
use App\Models\Subscription;
use App\Models\WebhookEvent;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A paid subscription checkout must become a subscription in the system.
 *
 * This is the wiring the webhook job was missing: orders/paid arrived, was logged
 * "unhandled", and thrown away — real customers paid for subscriptions that never
 * existed (order #71630, 15 Jul). The payloads here mirror the real order #72693.
 */
class PaidOrderIngestTest extends TestCase
{
    use RefreshDatabase;

    private const SUBS_PRODUCT_ID = '9991874183472';

    protected function setUp(): void
    {
        parent::setUp();

        config(['shopify.webhook_secret' => 'test-webhook-secret']);

        Product::query()->create([
            'shopify_product_id' => self::SUBS_PRODUCT_ID,
            'title' => 'מנוי מתחדש',
            'handle' => 'subs-test',
            'status' => 'active',
        ]);
    }

    /** A paid order shaped like the real #72693 (new theme, full properties). */
    private function subscriptionOrder(array $overrides = []): array
    {
        $quizData = json_encode([
            'discount' => 0.9,
            'interval' => 1,
            'status' => 'new-onboarding',
            'startDate' => '2026-08-03',
            'dogs' => [[
                'avatar' => 2,
                'status' => 'active',
                'name' => 'רקסי',
                'sex' => 0,
                'caloriesPerDay' => 281,
                'variants' => [],
                'quizData' => ['sex' => 0, 'age' => 3, 'weight' => 5, 'activity' => 0, 'body' => 1, 'allergy' => ''],
            ]],
        ], JSON_UNESCAPED_UNICODE);

        return array_replace([
            'id' => 18800000000001,
            'name' => '#90001',
            'processed_at' => '2026-08-03T08:43:00+03:00',
            'total_price' => '191.00',
            'customer' => [
                'id' => 8537351520560,
                'email' => 'buyer@example.com',
                'first_name' => 'אביעד',
                'last_name' => 'מ',
                'phone' => '+972521234567',
            ],
            'note_attributes' => [
                ['name' => 'quiz_dog_id', 'value' => 'qd-public-1'],
            ],
            'line_items' => [[
                'product_id' => (int) self::SUBS_PRODUCT_ID,
                'variant_id' => 66514429149488,
                'sku' => 'TB30 - אריזה יומית של 79 גרם',
                'title' => 'מנוי מתחדש',
                'quantity' => 1,
                'price' => '162.00',
                'properties' => [
                    ['name' => '_subscription', 'value' => 'true'],
                    ['name' => 'תדירות', 'value' => 'חודשי'],
                    ['name' => '_quiz_data', 'value' => $quizData],
                ],
            ]],
        ], $overrides);
    }

    private function deliver(array $order): void
    {
        $raw = json_encode($order);

        $this->call(
            'POST', '/shopify/webhooks', [], [], [],
            $this->transformHeadersToServerVars([
                'Content-Type' => 'application/json',
                'X-Shopify-Topic' => 'orders/paid',
                'X-Shopify-Webhook-Id' => 'wh-'.md5($raw),
                'X-Shopify-Hmac-Sha256' => base64_encode(hash_hmac('sha256', $raw, 'test-webhook-secret', true)),
            ]),
            $raw,
        )->assertStatus(202);
    }

    public function test_a_paid_subscription_checkout_creates_a_pending_subscription_and_its_dog(): void
    {
        $this->deliver($this->subscriptionOrder());

        $subscription = Subscription::query()->firstOrFail();
        $this->assertSame(SubscriptionStatus::PENDING, $subscription->status);
        $this->assertSame(PaymentState::NEEDS_CARD_UPDATE, $subscription->payment_state);
        $this->assertSame(1, (int) $subscription->frequency_months);
        $this->assertSame('18800000000001', $subscription->original_order_id);
        $this->assertSame('2026-09-03', $subscription->next_charge_at->toDateString());
        $this->assertSame(162.00, (float) $subscription->next_charge_amount);

        $customer = Customer::query()->firstOrFail();
        $this->assertSame('8537351520560', $customer->shopify_customer_id);
        $this->assertSame('buyer@example.com', $customer->email);

        $dog = Dog::query()->firstOrFail();
        $this->assertSame('רקסי', $dog->name);
        $this->assertSame($subscription->id, (int) $dog->subscription_id);
        $this->assertSame(['66514429149488'], $dog->selected_variants);
        $this->assertSame(281, (int) $dog->calories_per_day);
    }

    public function test_a_plain_shop_order_creates_nothing(): void
    {
        $this->deliver($this->subscriptionOrder([
            'line_items' => [[
                'product_id' => 111222333,
                'variant_id' => 444555666,
                'title' => 'חטיף עוף',
                'quantity' => 2,
                'price' => '39.00',
                'properties' => [],
            ]],
        ]));

        $this->assertSame(0, Subscription::query()->count());
        $this->assertSame(0, Customer::query()->count());
    }

    public function test_an_old_theme_order_with_no_properties_still_counts_by_product(): void
    {
        // The real #71630: only the product line, no properties, no quiz note.
        $order = $this->subscriptionOrder();
        $order['line_items'][0]['properties'] = [];
        $order['note_attributes'] = [];

        $this->deliver($order);

        $subscription = Subscription::query()->firstOrFail();
        $this->assertSame(SubscriptionStatus::PENDING, $subscription->status);

        // No quiz data — but the purchased flavors are still on a dog, or the
        // next cycle would have nothing to ship.
        $this->assertSame(['66514429149488'], Dog::query()->firstOrFail()->selected_variants);
    }

    public function test_webhook_redelivery_does_not_duplicate(): void
    {
        $order = $this->subscriptionOrder();

        $this->deliver($order);
        // A redelivery carries a NEW webhook id but the SAME order id.
        $order['note_attributes'][] = ['name' => 'redelivery', 'value' => '1'];
        $this->deliver($order);

        $this->assertSame(1, Subscription::query()->count());
        $this->assertSame(1, Dog::query()->count());
    }

    public function test_a_saved_quiz_dog_is_linked_instead_of_creating_a_second_dog(): void
    {
        $customer = Customer::query()->create([
            'shopify_customer_id' => '8537351520560',
            'email' => 'buyer@example.com',
        ]);
        $dog = Dog::query()->create(['customer_id' => $customer->id, 'name' => 'בלו']);
        QuizDog::query()->create([
            'public_id' => 'qd-public-1',
            'customer_id' => $customer->id,
            'linked_dog_id' => $dog->id,
            'payload' => [],
        ]);

        $this->deliver($this->subscriptionOrder());

        $this->assertSame(1, Dog::query()->count());
        $dog->refresh();
        $this->assertSame(Subscription::query()->firstOrFail()->id, (int) $dog->subscription_id);
        $this->assertSame('active', $dog->subscription_status);
    }

    public function test_the_backfill_command_ingests_stored_missed_webhooks(): void
    {
        // The event was stored and marked processed back when the job dropped it.
        WebhookEvent::query()->create([
            'webhook_id' => 'wh-old-1',
            'topic' => 'orders/paid',
            'payload' => $this->subscriptionOrder(),
            'status' => 'processed',
            'processed_at' => now()->subDays(3),
        ]);

        $this->artisan('mills:ingest-subscription-orders')
            ->expectsOutputToContain('created 1 subscription')
            ->assertExitCode(0);

        $this->assertSame(1, Subscription::query()->count());

        // Running it again changes nothing.
        $this->artisan('mills:ingest-subscription-orders')->assertExitCode(0);
        $this->assertSame(1, Subscription::query()->count());
    }

    public function test_two_flavor_checkout_sums_the_recurring_amount(): void
    {
        $order = $this->subscriptionOrder();
        $order['line_items'][] = [
            'product_id' => (int) self::SUBS_PRODUCT_ID,
            'variant_id' => 66514423972144,
            'sku' => 'SF30 - אריזה יומית של 133 גרם',
            'title' => 'מנוי מתחדש',
            'quantity' => 1,
            'price' => '246.60',
            'properties' => [['name' => '_subscription', 'value' => 'true']],
        ];

        $this->deliver($order);

        $subscription = Subscription::query()->firstOrFail();
        $this->assertSame(408.60, (float) $subscription->next_charge_amount);
        $this->assertSame(['66514429149488', '66514423972144'], Dog::query()->firstOrFail()->selected_variants);
    }
}
