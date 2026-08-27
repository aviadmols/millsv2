<?php

namespace Tests\Feature;

use App\Filament\Widgets\CardcomHandoff;
use App\Models\ActivityEvent;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Cardcom hand-off queue: every customer on it is being billed twice right now.
 *
 * Saving a card here makes us start charging them, but their old recurring charge lives on
 * in Cardcom and is removed only by a human in Cardcom's own admin. The queue exists so that
 * hand-off cannot be forgotten — and so "who says it was removed" always has a name on it.
 */
class CardcomHandoffTest extends TestCase
{
    use RefreshDatabase;

    private function migratedCustomer(string $email = 'moved@example.com'): Customer
    {
        $customer = Customer::query()->create([
            'email' => $email,
            'phone' => '0521230000',
            // The queue lists people who came FROM the old system — the imported gid is
            // what says so. A checkout-born customer with the same card source stays out.
            'legacy_shopify_gid' => 'gid://shopify/Metaobject/'.random_int(1000, 99999),
        ]);

        PaymentMethod::query()->create([
            'customer_id' => $customer->id,
            'gateway' => 'payme',
            'buyer_key' => 'bk',
            'masked_card' => '**** 4242',
            'is_active' => true,
            'source' => 'card_update',   // the marker of "moved off Cardcom onto us"
            'captured_at' => now(),
        ]);

        return $customer;
    }

    public function test_a_customer_who_saved_a_card_enters_the_queue(): void
    {
        $customer = $this->migratedCustomer();

        $this->assertTrue(CardcomHandoff::canView());
        $this->assertTrue(CardcomHandoff::pendingQuery()->whereKey($customer->id)->exists());
    }

    public function test_a_customer_imported_already_on_payme_is_not_queued(): void
    {
        $customer = Customer::query()->create(['email' => 'imported@example.com']);

        PaymentMethod::query()->create([
            'customer_id' => $customer->id,
            'gateway' => 'payme',
            'buyer_key' => 'bk',
            'is_active' => true,
            'source' => 'import',   // never billed by Cardcom — nothing to remove there
            'captured_at' => now(),
        ]);

        $this->assertFalse(CardcomHandoff::pendingQuery()->whereKey($customer->id)->exists());
    }

    public function test_confirming_records_who_and_when_and_clears_the_row(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $customer = $this->migratedCustomer();

        Livewire::test(CardcomHandoff::class)->call('confirm', $customer->id);

        $customer->refresh();

        // A name and a time, not a boolean: the failure this queue prevents is silent double
        // billing, and "who said the charge was removed" must always have an answer.
        $this->assertNotNull($customer->cardcom_removed_at);
        $this->assertSame($admin->getKey(), $customer->cardcom_removed_by);
        $this->assertFalse(CardcomHandoff::pendingQuery()->whereKey($customer->id)->exists());

        $event = ActivityEvent::query()->latest('id')->firstOrFail();
        $this->assertSame('cardcom_charge_removed', $event->details['action']);
        $this->assertSame('admin:'.$admin->getKey(), $event->actor);
    }

    public function test_an_empty_queue_hides_the_widget_entirely(): void
    {
        // Silence, not an empty box — the panel exists only while someone is double-billed.
        $this->assertFalse(CardcomHandoff::canView());
    }

    public function test_the_queue_appears_on_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create());
        $this->migratedCustomer();

        Livewire::test(CardcomHandoff::class)
            ->assertSee(__('dashboard.cardcom_heading'))
            ->assertSee('**** 4242')
            ->assertSee(__('dashboard.cardcom_confirm'));
    }
}
