<?php

namespace Tests\Feature;

use App\Filament\Resources\Subscriptions\Pages\CreateSubscription;
use App\Filament\Resources\Subscriptions\Pages\EditSubscription;
use App\Models\Customer;
use App\Models\Dog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Creating a subscription from the admin, dogs included.
 *
 * `dogs.customer_id` is NOT NULL and the relationship save fills in only subscription_id,
 * so the dog insert failed on every new subscription — AFTER the subscription row had
 * been written. The admin saw an error and a subscription that existed anyway, with no
 * dogs on it (2026-09-08, subscription 939).
 */
class CreateSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private function variant(): ProductVariant
    {
        $product = Product::query()->create(['shopify_product_id' => 'p-1', 'title' => 'Food']);

        return ProductVariant::query()->create([
            'shopify_variant_id' => '111',
            'product_id' => $product->id,
            'title' => '1.5kg',
            'price' => 171.00,
        ]);
    }

    public function test_a_new_subscription_is_created_with_its_dog_attached_to_the_customer(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::query()->create(['email' => 'new@example.com', 'shopify_customer_id' => '900900']);
        $this->variant();

        Livewire::test(CreateSubscription::class)
            ->fillForm([
                'customer_id' => $customer->id,
                'status' => SubscriptionStatus::ACTIVE->value,
                'payment_state' => PaymentState::PAYME->value,
                'frequency_months' => 1,
                'next_charge_at' => now()->addDays(7)->toDateString(),
                'dogs' => [
                    ['name' => 'Rex', 'weight' => 12, 'age' => 4, 'selected_variants' => ['111']],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $subscription = Subscription::query()->where('customer_id', $customer->id)->firstOrFail();
        $dog = Dog::query()->where('subscription_id', $subscription->id)->firstOrFail();

        // The whole point: the dog knows whose it is.
        $this->assertSame($customer->id, $dog->customer_id);
        $this->assertSame('Rex', $dog->name);
    }

    public function test_editing_an_existing_subscription_still_works_with_its_dogs_on_the_form(): void
    {
        /*
         * The create-path fix type-hinted the SAVE mutator to the subscription too — but on
         * save Filament injects the related model being saved, the dog. Every edit of an
         * existing subscription then threw, which is how a delivery-date change for
         * padonyi@ failed the same afternoon.
         */
        $this->actingAs(User::factory()->create());
        $customer = Customer::query()->create(['email' => 'edit@example.com', 'shopify_customer_id' => '900902']);
        $this->variant();

        $subscription = new Subscription;
        $subscription->fill([
            'customer_id' => $customer->id,
            'payment_state' => PaymentState::PAYME->value,
            'frequency_months' => 1,
            'next_charge_at' => '2026-09-20',
        ]);
        $subscription->forceFill(['status' => SubscriptionStatus::ACTIVE->value])->save();

        Dog::query()->create([
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'name' => 'Rex',
            'status' => 'active',
            'selected_variants' => ['gid://shopify/ProductVariant/111'],
            'addons_products' => [],
        ]);

        Livewire::test(EditSubscription::class, ['record' => $subscription->getRouteKey()])
            ->fillForm(['next_charge_at' => '2026-10-05'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('2026-10-05', $subscription->fresh()->next_charge_at->toDateString());
        $this->assertSame($customer->id, Dog::query()->firstOrFail()->customer_id);
    }

    public function test_a_dog_added_to_an_existing_subscription_also_gets_its_customer(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::query()->create(['email' => 'add@example.com', 'shopify_customer_id' => '900903']);
        $this->variant();

        $subscription = new Subscription;
        $subscription->fill(['customer_id' => $customer->id, 'payment_state' => PaymentState::PAYME->value, 'frequency_months' => 1]);
        $subscription->forceFill(['status' => SubscriptionStatus::ACTIVE->value])->save();

        Livewire::test(EditSubscription::class, ['record' => $subscription->getRouteKey()])
            ->fillForm(['dogs' => [['name' => 'Luna', 'weight' => 8, 'age' => 2, 'selected_variants' => ['111']]]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($customer->id, Dog::query()->where('name', 'Luna')->firstOrFail()->customer_id);
    }

    public function test_a_subscription_with_no_dogs_is_still_creatable(): void
    {
        // The dogs can be added afterwards; the default used to be one blank, nameless dog.
        $this->actingAs(User::factory()->create());
        $customer = Customer::query()->create(['email' => 'nodog@example.com', 'shopify_customer_id' => '900901']);

        Livewire::test(CreateSubscription::class)
            ->fillForm([
                'customer_id' => $customer->id,
                'status' => SubscriptionStatus::ACTIVE->value,
                'payment_state' => PaymentState::PAYME->value,
                'frequency_months' => 1,
                'dogs' => [],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, Subscription::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(0, Dog::query()->count());
    }
}
