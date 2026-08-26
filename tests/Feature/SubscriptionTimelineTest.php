<?php

namespace Tests\Feature;

use App\Filament\Resources\Subscriptions\Pages\ViewSubscription;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\ActivityEvent;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Support\Timeline;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The subscription's own history, on the subscription's own screen.
 *
 * The Activity feed already listed every event in the system; what nobody could answer
 * without leaving the page and filtering was "what happened to THIS one". Two things
 * matter here: the feed shows this subscription and not the customer's other one, and a
 * note written by a person is signed with their name — an unsigned note is worthless to
 * whoever reads it next.
 */
class SubscriptionTimelineTest extends TestCase
{
    use RefreshDatabase;

    private function subscription(?Customer $customer = null): Subscription
    {
        $customer ??= Customer::query()->create([
            'email' => 'tl'.uniqid().'@example.com',
            'shopify_customer_id' => (string) random_int(1000, 99999),
        ]);

        $subscription = new Subscription;
        $subscription->fill([
            'customer_id' => $customer->id,
            'payment_state' => PaymentState::PAYME->value,
            'frequency_months' => 1,
            'next_charge_at' => now()->addDays(3),
            'next_charge_amount' => 153.90,
        ]);
        $subscription->forceFill(['status' => SubscriptionStatus::ACTIVE->value])->save();

        return $subscription;
    }

    public function test_the_screen_shows_this_subscriptions_events_in_plain_hebrew(): void
    {
        $this->actingAs(User::factory()->create());
        $subscription = $this->subscription();

        Timeline::record(Timeline::KIND_CHARGE_SUCCEEDED, ['amount' => '153.90'], $subscription->id, $subscription->customer_id);

        $this->get(SubscriptionResource::getUrl('view', ['record' => $subscription]))
            ->assertOk()
            ->assertSee(__('activity.kind_charge_succeeded'), false)
            ->assertSee('₪153.90', false);
    }

    public function test_another_subscriptions_events_stay_on_their_own_screen(): void
    {
        $this->actingAs(User::factory()->create());

        $customer = Customer::query()->create([
            'email' => 'two@example.com',
            'shopify_customer_id' => '5150',
        ]);

        $mine = $this->subscription($customer);
        $theirs = $this->subscription($customer);   // same customer, different subscription

        Timeline::record(Timeline::KIND_ADMIN_NOTE, ['note' => 'שייך למנוי האחר'], $theirs->id, $customer->id);
        // A customer-level event (no subscription of its own) DOES belong on both.
        Timeline::record(Timeline::KIND_ADDRESS_UPDATED, [], null, $customer->id, Timeline::ACTOR_CUSTOMER);

        $this->get(SubscriptionResource::getUrl('view', ['record' => $mine]))
            ->assertOk()
            ->assertDontSee('שייך למנוי האחר', false)
            ->assertSee(__('activity.sum_address'), false);
    }

    public function test_a_note_is_signed_with_the_name_of_whoever_wrote_it(): void
    {
        $admin = User::factory()->create(['name' => 'רונית מהתמיכה']);
        $this->actingAs($admin);

        $subscription = $this->subscription();

        Livewire::test(ViewSubscription::class, ['record' => $subscription->getKey()])
            ->mountAction(TestAction::make('addNote')->schemaComponent('timeline'))
            ->setActionData(['note' => "הלקוחה ביקשה לדחות בשבוע.\nלבדוק שוב ביום ראשון."])
            ->callMountedAction();

        $event = ActivityEvent::query()->where('kind', Timeline::KIND_ADMIN_NOTE)->firstOrFail();

        $this->assertSame($subscription->id, (int) $event->subscription_id);
        $this->assertSame('admin:'.$admin->id, $event->actor);
        $this->assertStringContainsString('לדחות בשבוע', $event->details['note']);

        // And it reads back on the screen as the author's prose, under their name.
        $this->get(SubscriptionResource::getUrl('view', ['record' => $subscription]))
            ->assertOk()
            ->assertSee('לבדוק שוב ביום ראשון', false)
            ->assertSee('רונית מהתמיכה', false);
    }

    public function test_a_note_of_nothing_but_spaces_is_not_recorded(): void
    {
        // `required` is happy with three spaces — the guard that matters is the trim in
        // the action, and an empty row in a history is worse than no row.
        $this->actingAs(User::factory()->create());
        $subscription = $this->subscription();

        Livewire::test(ViewSubscription::class, ['record' => $subscription->getKey()])
            ->mountAction(TestAction::make('addNote')->schemaComponent('timeline'))
            ->setActionData(['note' => '   '])
            ->callMountedAction();

        $this->assertSame(0, ActivityEvent::query()->count());
    }
}
