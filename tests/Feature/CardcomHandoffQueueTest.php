<?php

namespace Tests\Feature;

use App\Filament\Widgets\CardcomHandoff;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Modules\MillsSubscriptions\Services\CardUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Cardcom hand-off queue lists exactly one kind of person: a customer from the OLD
 * system who updated their card here — meaning we now bill them, while their Cardcom
 * recurring charge lives on until a human removes it. Anyone else in that list sends an
 * admin hunting through Cardcom for a charge that does not exist: a checkout-born
 * customer was never billed by Cardcom (27 Aug: the day's test order appeared there).
 */
class CardcomHandoffQueueTest extends TestCase
{
    use RefreshDatabase;

    private function legacyCustomer(): Customer
    {
        return Customer::query()->create([
            'email' => 'legacy'.uniqid().'@example.com',
            'shopify_customer_id' => (string) random_int(1000, 99999),
            'legacy_shopify_gid' => 'gid://shopify/Metaobject/'.random_int(1000, 99999),
        ]);
    }

    public function test_a_legacy_customer_who_updated_their_card_is_queued_for_cardcom_removal(): void
    {
        $customer = $this->legacyCustomer();

        // The card-update flow stores the card — from here WE bill, Cardcom still does too.
        app(CardUpdateService::class)->storeBuyerKey($customer, 'bk_legacy', '**** 3428');

        $this->assertTrue(CardcomHandoff::pendingQuery()->whereKey($customer->id)->exists());
    }

    public function test_a_checkout_born_customer_never_enters_the_cardcom_queue(): void
    {
        // No legacy gid, and the card came from their own checkout — Cardcom never knew them.
        $customer = Customer::query()->create([
            'email' => 'new@example.com',
            'shopify_customer_id' => '8537351520560',
        ]);

        app(CardUpdateService::class)->storeBuyerKey($customer, 'bk_checkout', '**** 3428', source: 'checkout');

        $this->assertFalse(CardcomHandoff::pendingQuery()->exists());
    }

    public function test_even_a_portal_card_update_does_not_queue_a_customer_cardcom_never_billed(): void
    {
        // A NEW customer updating their card in the portal gets source 'card_update' too —
        // origin, not source alone, is what decides.
        $customer = Customer::query()->create([
            'email' => 'new2@example.com',
            'shopify_customer_id' => '999111',
        ]);

        app(CardUpdateService::class)->storeBuyerKey($customer, 'bk_portal', '**** 1111');

        $this->assertFalse(CardcomHandoff::pendingQuery()->exists());
    }

    public function test_confirming_removes_the_row_permanently(): void
    {
        $customer = $this->legacyCustomer();
        app(CardUpdateService::class)->storeBuyerKey($customer, 'bk_legacy', '**** 3428');

        $customer->forceFill(['cardcom_removed_at' => now(), 'cardcom_removed_by' => User::factory()->create()->id])->save();

        $this->assertFalse(CardcomHandoff::pendingQuery()->exists());
    }

    public function test_a_replaced_card_does_not_resurrect_a_confirmed_row(): void
    {
        // The admin removed the Cardcom charge; months later the customer updates their
        // card again. There is nothing left in Cardcom — the row must stay gone.
        $customer = $this->legacyCustomer();
        app(CardUpdateService::class)->storeBuyerKey($customer, 'bk_first', '**** 1111');
        $customer->forceFill(['cardcom_removed_at' => now(), 'cardcom_removed_by' => User::factory()->create()->id])->save();

        app(CardUpdateService::class)->storeBuyerKey($customer, 'bk_second', '**** 2222');

        $this->assertFalse(CardcomHandoff::pendingQuery()->exists());
        $this->assertSame(1, PaymentMethod::query()->where('is_active', true)->count());
    }
}
