<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Dog;
use App\Models\Subscription;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Support\StorefrontToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves the FROZEN storefront contract (SYSTEM-MAP §3.3) that the Shopify theme
 * depends on: the /me payload shape, the card-update wall (403
 * icount_requires_card_update), ownership isolation (404, never 403), and the
 * legacy body-field aliases (dogIds / variantIds).
 */
class StorefrontContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shopify.storefront_token_secret' => 'test-secret-for-storefront-token']);
    }

    private function customerWithSubscription(PaymentState $state = PaymentState::PAYME): array
    {
        $customer = Customer::query()->create([
            'shopify_customer_id' => '900100',
            'email' => 'dog@example.com',
            'first_name' => 'Aviad',
            'last_name' => 'M',
        ]);

        $subscription = new Subscription;
        $subscription->fill([
            'customer_id' => $customer->id,
            'payment_state' => $state->value,
            'frequency_months' => 1,
            'next_charge_at' => '2026-08-01 00:00:00',
        ]);
        $subscription->forceFill(['status' => SubscriptionStatus::ACTIVE->value])->save();

        $dog = Dog::query()->create([
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'name' => 'Rex',
            'status' => 'active',
            'selected_variants' => ['gid://shopify/ProductVariant/111'],
            'addons_products' => [],
        ]);

        return [$customer, $subscription, $dog];
    }

    private function auth(Customer $customer): array
    {
        return ['Authorization' => 'Bearer '.StorefrontToken::mint((string) $customer->shopify_customer_id)];
    }

    public function test_me_returns_the_frozen_payload_shape(): void
    {
        [$customer] = $this->customerWithSubscription();

        $response = $this->getJson('/storefront/me', $this->auth($customer));

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.customer.numeric_id', '900100')
            ->assertJsonPath('data.customer.display_name', 'Aviad M')
            ->assertJsonPath('data.subscriptions.0.status', 'active')
            ->assertJsonPath('data.subscriptions.0.frequency', 'Monthly')
            ->assertJsonPath('data.subscriptions.0.charge_cycle', '2026-08-01')
            ->assertJsonPath('data.subscriptions.0.integration_source', 'payme')
            ->assertJsonPath('data.subscriptions.0.requires_card_update', false)
            ->assertJsonPath('data.subscriptions.0.dogs.0.name', 'Rex')
            ->assertJsonPath('data.flags.is_empty', false)
            ->assertJsonStructure([
                'ok',
                'data' => [
                    'customer' => ['id', 'numeric_id', 'email', 'display_name', 'addresses'],
                    'subscriptions' => [['id', 'numeric_id', 'status', 'frequency', 'charge_cycle',
                        'integration_source', 'requires_card_update', 'draft_order_id', 'dogs',
                        'subscription_products']],
                    'dogs',
                    'flags' => ['is_icount', 'any_requires_card_update', 'is_legacy_note', 'is_empty'],
                ],
            ]);
    }

    public function test_a_subscription_needing_a_card_is_presented_as_icount_and_blocks_billing_writes(): void
    {
        [$customer, $subscription] = $this->customerWithSubscription(PaymentState::NEEDS_CARD_UPDATE);

        $this->getJson('/storefront/me', $this->auth($customer))
            ->assertOk()
            ->assertJsonPath('data.subscriptions.0.integration_source', 'icount')
            ->assertJsonPath('data.subscriptions.0.requires_card_update', true)
            ->assertJsonPath('data.flags.any_requires_card_update', true);

        // The exact 403 code the theme keys on.
        $this->patchJson("/storefront/me/subscription/{$subscription->id}", [
            'frequency' => 'Every 2 Months',
        ], $this->auth($customer))
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'icount_requires_card_update')
            ->assertJsonPath('requires_card_update', true);
    }

    public function test_customer_cannot_touch_another_customers_subscription_and_gets_404_not_403(): void
    {
        [$customer] = $this->customerWithSubscription();

        $stranger = Customer::query()->create(['shopify_customer_id' => '999999', 'email' => 'x@y.z']);
        $strangerSub = new Subscription;
        $strangerSub->fill(['customer_id' => $stranger->id, 'payment_state' => PaymentState::PAYME->value, 'frequency_months' => 1]);
        $strangerSub->forceFill(['status' => SubscriptionStatus::ACTIVE->value])->save();

        $this->patchJson("/storefront/me/subscription/{$strangerSub->id}", [
            'frequency' => 'Monthly',
        ], $this->auth($customer))
            ->assertStatus(404)
            ->assertJsonPath('error', 'not_found');
    }

    public function test_subscription_update_changes_frequency_and_charge_cycle(): void
    {
        [$customer, $subscription] = $this->customerWithSubscription();

        $this->patchJson("/storefront/me/subscription/{$subscription->id}", [
            'frequency' => 'Every 2 Months',
            'charge_cycle' => '2026-09-15',
        ], $this->auth($customer))
            ->assertOk()
            ->assertJsonPath('data.subscription.frequency', 'Every 2 Months')
            ->assertJsonPath('data.subscription.charge_cycle', '2026-09-15');

        $this->assertSame(2, (int) $subscription->fresh()->frequency_months);
    }

    public function test_dog_variant_change_accepts_the_legacy_variant_ids_alias(): void
    {
        [$customer, , $dog] = $this->customerWithSubscription();

        $this->patchJson("/storefront/me/dogs/{$dog->id}/change-variant", [
            'variantIds' => ['gid://shopify/ProductVariant/222', 'gid://shopify/ProductVariant/333'],
        ], $this->auth($customer))
            ->assertOk()
            ->assertJsonPath('data.dog.selected_variants', [
                'gid://shopify/ProductVariant/222',
                'gid://shopify/ProductVariant/333',
            ]);
    }

    public function test_addons_can_be_added_and_removed(): void
    {
        [$customer, , $dog] = $this->customerWithSubscription();
        $auth = $this->auth($customer);

        $this->patchJson("/storefront/me/dogs/{$dog->id}/addons/add", ['variantId' => 'v-1'], $auth)
            ->assertOk()
            ->assertJsonPath('data.dog.addons_products', ['v-1']);

        $this->patchJson("/storefront/me/dogs/{$dog->id}/addons/remove", ['variantId' => 'v-1'], $auth)
            ->assertOk()
            ->assertJsonPath('data.dog.addons_products', []);
    }

    public function test_removing_the_last_dog_drops_the_subscription_back_to_pending(): void
    {
        [$customer, $subscription, $dog] = $this->customerWithSubscription();

        $this->patchJson("/storefront/me/subscription/{$subscription->id}/remove-dog", [
            'dogIds' => [(string) $dog->id],       // legacy plural alias
        ], $this->auth($customer))->assertOk();

        $this->assertSame(SubscriptionStatus::PENDING, $subscription->fresh()->status);
    }

    public function test_address_update_writes_locally_and_reports_shopify_sync_state(): void
    {
        [$customer] = $this->customerWithSubscription();

        $this->patchJson('/storefront/me/address', [
            'address1' => 'הרצל 10',
            'city' => 'תל אביב',
            'zip' => '6100000',
        ], $this->auth($customer))
            ->assertOk()
            ->assertJsonPath('data.address.address1', 'הרצל 10')
            ->assertJsonPath('data.address.city', 'תל אביב')
            // Not connected to Shopify in tests → local write still succeeds.
            ->assertJsonPath('data.shopify_synced', false);

        $this->assertSame('הרצל 10', $customer->fresh()->address1);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/storefront/me')
            ->assertStatus(401)
            ->assertJsonPath('error', 'unauthenticated');
    }
}
