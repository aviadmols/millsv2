<?php

namespace Tests\Feature;

use App\Filament\Resources\Subscriptions\Pages\EditSubscription;
use App\Models\ActivityEvent;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Support\Timeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Subscription 62 (2026-10-28): a card saved at 17:18 was "card required" again at 17:20,
 * and nothing on record said what had put it back.
 */
class PaymentStateAuditTest extends TestCase
{
    use RefreshDatabase;

    private function subscription(PaymentState $state): Subscription
    {
        $customer = Customer::query()->create(['email' => uniqid('p', true).'@example.com', 'shopify_customer_id' => (string) random_int(1, 9_999_999)]);

        $subscription = new Subscription;
        $subscription->fill(['customer_id' => $customer->id, 'payment_state' => $state->value, 'frequency_months' => 1]);
        $subscription->forceFill(['status' => SubscriptionStatus::ACTIVE->value])->save();

        return $subscription->refresh();
    }

    public function test_every_change_of_the_billing_gate_lands_on_the_timeline_with_who_did_it(): void
    {
        $subscription = $this->subscription(PaymentState::NEEDS_CARD_UPDATE);
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $subscription->forceFill(['payment_state' => PaymentState::PAYME->value])->save();

        $event = ActivityEvent::query()->where('kind', Timeline::KIND_PAYMENT_STATE_CHANGED)->firstOrFail();
        $this->assertSame($subscription->id, (int) $event->subscription_id);
        $this->assertSame('admin:'.$admin->id, $event->actor);
        $this->assertSame(['from' => 'needs_card_update', 'to' => 'payme'], $event->details);
    }

    public function test_saving_an_open_edit_form_does_not_undo_a_card_update_made_meanwhile(): void
    {
        $this->actingAs(User::factory()->create());
        $subscription = $this->subscription(PaymentState::NEEDS_CARD_UPDATE);

        $form = Livewire::test(EditSubscription::class, ['record' => $subscription->getRouteKey()]);

        // The card is saved in another tab while the form is open.
        $subscription->fresh()->forceFill(['payment_state' => PaymentState::PAYME->value])->save();

        $form->fillForm(['frequency_months' => 2])->call('save')->assertHasNoFormErrors();

        $fresh = $subscription->fresh();
        $this->assertSame(PaymentState::PAYME, $fresh->payment_state);
        $this->assertSame(2, $fresh->frequency_months);
    }

    public function test_a_payment_state_the_admin_deliberately_changes_is_still_saved(): void
    {
        $this->actingAs(User::factory()->create());
        $subscription = $this->subscription(PaymentState::PAYME);

        Livewire::test(EditSubscription::class, ['record' => $subscription->getRouteKey()])
            ->fillForm(['payment_state' => PaymentState::NO_CHARGE->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(PaymentState::NO_CHARGE, $subscription->fresh()->payment_state);
    }

    public function test_a_status_changed_by_the_customer_meanwhile_survives_the_save(): void
    {
        $this->actingAs(User::factory()->create());
        $subscription = $this->subscription(PaymentState::PAYME);

        $form = Livewire::test(EditSubscription::class, ['record' => $subscription->getRouteKey()]);
        $subscription->fresh()->transitionTo(SubscriptionStatus::CANCELLED);

        $form->fillForm(['frequency_months' => 2])->call('save')->assertHasNoFormErrors();

        $this->assertSame(SubscriptionStatus::CANCELLED, $subscription->fresh()->status);
    }
}
