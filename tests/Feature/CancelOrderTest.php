<?php

namespace Tests\Feature;

use App\Filament\Resources\Subscriptions\Pages\ViewSubscription;
use App\Models\Customer;
use App\Models\PaymentLedger;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\MillsSubscriptions\Enums\LedgerStatus;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Services\PayMe\PaymeClient;
use App\Modules\MillsSubscriptions\Services\Shopify\ShopifyAdminClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * Cancelling an order from the subscription screen, with the refund as a choice.
 *
 * Two decisions, and the order they run in is the point. The money goes back through
 * PayMe FIRST — Shopify's own cancel can carry a refund that would be recorded and never
 * paid — and only then is the order closed. If the refund fails, nothing is cancelled:
 * a customer with no box and no money back is the one outcome worse than doing nothing.
 */
class CancelOrderTest extends TestCase
{
    use RefreshDatabase;

    private array $calls = [];

    private function scenario(float $charged = 358.20, bool $withLedger = true): Subscription
    {
        $customer = Customer::query()->create(['email' => 'c@example.com', 'shopify_customer_id' => '900950']);

        $subscription = new Subscription;
        $subscription->fill([
            'customer_id' => $customer->id,
            'payment_state' => PaymentState::PAYME->value,
            'frequency_months' => 1,
        ]);
        $subscription->forceFill(['status' => SubscriptionStatus::ACTIVE->value])->save();

        if ($withLedger) {
            $row = PaymentLedger::query()->create([
                'subscription_id' => $subscription->id,
                'customer_id' => $customer->id,
                'context' => 'recurring',
                'idempotency_key' => uniqid('k', true),
                'amount' => $charged,
                'currency' => 'ILS',
                'executed_at' => now(),
                'payme_transaction_id' => 'SALE-9',
                'shopify_order_id' => '74868',
            ]);
            $row->forceFill(['status' => LedgerStatus::SUCCEEDED->value])->save();
        }

        return $subscription->fresh();
    }

    private function fakeGateways(array $paymeAnswer = ['status_code' => 0]): void
    {
        $this->calls = [];

        $payme = Mockery::mock(PaymeClient::class);
        $payme->shouldReceive('refundSale')->andReturnUsing(function (string $sale, ?int $agorot) use ($paymeAnswer) {
            $this->calls[] = ['payme_refund' => $sale, 'agorot' => $agorot];

            return $paymeAnswer;
        });
        $this->app->instance(PaymeClient::class, $payme);

        $shopify = Mockery::mock(ShopifyAdminClient::class);
        $shopify->shouldReceive('isConnected')->andReturnTrue();
        $shopify->shouldReceive('restGet')->andReturn(['transactions' => []]);
        $shopify->shouldReceive('graphql')->andReturn(['data' => []]);
        $shopify->shouldReceive('restPost')->andReturnUsing(function (string $path, array $body) {
            $this->calls[] = ['shopify' => $path];

            return ['order' => ['id' => 74868, 'cancelled_at' => now()->toIso8601String()]];
        });
        $this->app->instance(ShopifyAdminClient::class, $shopify);
    }

    private function paths(): array
    {
        return array_values(array_filter(array_map(fn ($c) => $c['shopify'] ?? null, $this->calls)));
    }

    public function test_cancelling_with_a_refund_returns_the_money_first_then_closes_the_order(): void
    {
        $this->actingAs(User::factory()->create());
        $subscription = $this->scenario();
        $this->fakeGateways();

        Livewire::test(ViewSubscription::class, ['record' => $subscription->getRouteKey()])
            ->callAction('cancelOrder', data: ['refund' => true, 'amount' => 358.20, 'restock' => true], arguments: ['order_id' => '74868']);

        // PayMe was asked before Shopify was told to cancel.
        $this->assertSame('SALE-9', $this->calls[0]['payme_refund']);
        $this->assertSame(35820, $this->calls[0]['agorot']);
        $this->assertContains('orders/74868/cancel.json', $this->paths());

        $this->assertSame(LedgerStatus::REFUNDED, PaymentLedger::query()->firstOrFail()->status);
    }

    public function test_cancelling_without_a_refund_touches_no_money(): void
    {
        // The common case: the box already went out and the customer simply stops here.
        $this->actingAs(User::factory()->create());
        $subscription = $this->scenario();
        $this->fakeGateways();

        Livewire::test(ViewSubscription::class, ['record' => $subscription->getRouteKey()])
            ->callAction('cancelOrder', data: ['refund' => false, 'restock' => true], arguments: ['order_id' => '74868']);

        $this->assertSame([], array_filter($this->calls, fn ($c) => isset($c['payme_refund'])));
        $this->assertContains('orders/74868/cancel.json', $this->paths());
        $this->assertSame(LedgerStatus::SUCCEEDED, PaymentLedger::query()->firstOrFail()->status);
    }

    public function test_a_refused_refund_leaves_the_order_uncancelled(): void
    {
        /*
         * The order that matters. If the money did not go back, cancelling anyway would
         * leave the customer with neither the box nor the refund — and nothing on screen
         * would say which half had happened.
         */
        $this->actingAs(User::factory()->create());
        $subscription = $this->scenario();
        $this->fakeGateways(['status_code' => 1, 'status_error_details' => 'refused']);

        Livewire::test(ViewSubscription::class, ['record' => $subscription->getRouteKey()])
            ->callAction('cancelOrder', data: ['refund' => true, 'amount' => 358.20, 'restock' => true], arguments: ['order_id' => '74868']);

        $this->assertNotContains('orders/74868/cancel.json', $this->paths());
        $this->assertSame(LedgerStatus::SUCCEEDED, PaymentLedger::query()->firstOrFail()->status);
    }

    public function test_an_order_this_system_never_charged_can_still_be_cancelled(): void
    {
        // A checkout order has no ledger row here; cancelling it must still work, with the
        // refund simply unavailable.
        $this->actingAs(User::factory()->create());
        $subscription = $this->scenario(withLedger: false);
        $this->fakeGateways();

        Livewire::test(ViewSubscription::class, ['record' => $subscription->getRouteKey()])
            ->callAction('cancelOrder', data: ['refund' => false, 'restock' => false], arguments: ['order_id' => '74868']);

        $this->assertContains('orders/74868/cancel.json', $this->paths());
    }
}
