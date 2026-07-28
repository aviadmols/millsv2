<?php

namespace Tests\Feature;

use App\Filament\Resources\Activity\ActivityResource;
use App\Models\ActivityEvent;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Services\SubscriptionActions;
use App\Modules\MillsSubscriptions\Support\Timeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One feed that answers "what happened", in business language.
 *
 * The events were being written and never read: activity_events had no screen at all, so a
 * quiz becoming a customer, a charge, or an admin pausing a subscription were recorded into
 * a table nobody could open. And two of the actions people ask about most — pause and resume
 * — were not recorded on the feed at all, only in the engineering log.
 */
class ActivityFeedTest extends TestCase
{
    use RefreshDatabase;

    private function subscription(): Subscription
    {
        $customer = Customer::query()->create(['email' => 'feed@example.com', 'phone' => '0521110000']);

        $subscription = new Subscription;
        $subscription->fill([
            'customer_id' => $customer->id,
            'payment_state' => PaymentState::PAYME->value,
            'frequency_months' => 1,
            'next_charge_at' => now()->addDays(10),
        ]);
        $subscription->forceFill(['status' => SubscriptionStatus::ACTIVE->value])->save();

        return $subscription;
    }

    public function test_pausing_lands_on_the_feed_with_its_reason(): void
    {
        $subscription = $this->subscription();

        app(SubscriptionActions::class)->pause($subscription, 'customer travelling');

        $events = ActivityEvent::query()->where('kind', Timeline::KIND_STATUS_CHANGED)->get();

        // Exactly ONE row. transitionTo() already records the change, so an extra record in
        // pause() would make the feed claim the subscription was paused twice.
        $this->assertCount(1, $events);

        $details = $events->first()->details;
        $this->assertSame('active', $details['from']);
        $this->assertSame('paused', $details['to']);
        // "Why did this customer stop being charged in March" needs the reason, not just the
        // fact — and it has to sit beside the charges, not only in the engineering log.
        $this->assertSame('customer travelling', $details['reason']);
        $this->assertSame($subscription->customer_id, $events->first()->customer_id);
    }

    public function test_resuming_lands_on_the_feed(): void
    {
        $subscription = $this->subscription();
        $actions = app(SubscriptionActions::class);

        $actions->pause($subscription);
        $actions->resume($subscription->fresh());

        $transitions = ActivityEvent::query()
            ->where('kind', Timeline::KIND_STATUS_CHANGED)
            ->get()
            ->map(fn (ActivityEvent $e) => $e->details['from'].'→'.$e->details['to'])
            ->all();

        $this->assertSame(['active→paused', 'paused→active'], $transitions);
    }

    public function test_an_admin_action_is_attributed_to_that_admin(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        app(SubscriptionActions::class)->pause($this->subscription());

        $event = ActivityEvent::query()->latest('id')->firstOrFail();

        // Not "system": a person did this, and the feed has to be able to say which one.
        $this->assertSame('admin:'.$admin->getKey(), $event->actor);
    }

    public function test_an_unattended_action_is_not_attributed_to_a_person(): void
    {
        // No authenticated user (the scheduler, a webhook). Guessing a name would be worse
        // than admitting there isn't one.
        app(SubscriptionActions::class)->pause($this->subscription());

        $this->assertSame(Timeline::ACTOR_SYSTEM, ActivityEvent::query()->latest('id')->firstOrFail()->actor);
    }

    public function test_the_feed_renders_events_in_plain_language(): void
    {
        $subscription = $this->subscription();

        Timeline::record(Timeline::KIND_CHARGE_SUCCEEDED, ['amount' => '153.90'],
            $subscription->id, $subscription->customer_id);
        Timeline::record(Timeline::KIND_QUIZ_LINKED, ['dog_name' => 'רקס', 'weight' => 10, 'variants' => 1],
            null, $subscription->customer_id, Timeline::ACTOR_CUSTOMER);

        $this->actingAs(User::factory()->create());

        $html = $this->get(ActivityResource::getUrl('index'))->assertOk()->getContent();

        // A row of raw JSON is a log nobody reads twice.
        $this->assertStringContainsString(__('activity.kind_charge_succeeded'), $html);
        $this->assertStringContainsString('153.90', $html);
        $this->assertStringContainsString(__('activity.kind_quiz_linked'), $html);
        $this->assertStringContainsString('רקס', $html);
    }

    public function test_the_feed_is_read_only(): void
    {
        $subscription = $this->subscription();
        Timeline::record(Timeline::KIND_NOTE, [], $subscription->id, $subscription->customer_id);
        $record = ActivityEvent::query()->firstOrFail();

        // The value of a record of what happened is that it cannot be rewritten afterwards.
        $this->assertFalse(ActivityResource::canCreate());
        $this->assertFalse(ActivityResource::canEdit($record));
        $this->assertFalse(ActivityResource::canDelete($record));
    }
}
