<?php

namespace Tests\Feature;

use App\Models\ActivityEvent;
use App\Models\Customer;
use App\Models\Subscription;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Support\Timeline;
use App\Support\StorefrontToken;
use App\Support\Ui\EventPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the customer changes about their own plan, and what the timeline says about it.
 *
 * The timeline's whole job is to answer "what did this person do". Recording that some
 * FIELDS were touched does not answer it — and worse, it was recorded as a nested array,
 * which the presenter drops from a one-line summary, so every self-service change appeared
 * on the subscription screen as an empty "Note" row.
 */
class StorefrontPlanChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shopify.storefront_token_secret' => 'test-secret-for-storefront-token']);
    }

    private function subscription(array $overrides = []): Subscription
    {
        $customer = Customer::query()->create([
            'email' => 'plan@example.com',
            'shopify_customer_id' => '900500',
        ]);

        $subscription = new Subscription;
        $subscription->fill(array_merge([
            'customer_id' => $customer->id,
            'payment_state' => PaymentState::PAYME->value,
            'frequency_months' => 1,
            'next_charge_at' => '2026-09-08 00:00:00',
        ], $overrides));

        $subscription->forceFill([
            'status' => $overrides['status'] ?? SubscriptionStatus::ACTIVE->value,
        ])->save();

        return $subscription->fresh();
    }

    private function auth(Subscription $subscription): array
    {
        return ['Authorization' => 'Bearer '.StorefrontToken::mint((string) $subscription->customer->shopify_customer_id)];
    }

    public function test_a_plan_change_is_recorded_with_both_values_not_a_list_of_field_names(): void
    {
        $subscription = $this->subscription();

        $this->patchJson('/storefront/me/subscription/'.$subscription->id, [
            'frequency' => 'Every 2 Months',
            'charge_cycle' => '2026-09-15',
        ], $this->auth($subscription))->assertSuccessful();

        $event = ActivityEvent::query()->where('kind', Timeline::KIND_PLAN_UPDATED)->firstOrFail();

        $this->assertSame(1, $event->details['frequency_from']);
        $this->assertSame(2, $event->details['frequency_to']);
        $this->assertSame('2026-09-08', $event->details['charge_date_from']);
        $this->assertSame('2026-09-15', $event->details['charge_date_to']);
        $this->assertSame(Timeline::ACTOR_CUSTOMER, $event->actor);

        // And it READS as something — the failure this replaces was a visibly blank row.
        $summary = EventPresenter::summarize($event);

        $this->assertStringContainsString(__('subscriptions.every_2_months'), $summary);
        $this->assertStringContainsString('2026-09-15', $summary);
    }

    public function test_saving_without_changing_anything_leaves_no_trace(): void
    {
        // A row that says "the customer changed their plan" when they changed nothing is
        // noise in the one place support goes to find out what happened.
        $subscription = $this->subscription();

        $this->patchJson('/storefront/me/subscription/'.$subscription->id, [
            'frequency' => 'Monthly',
            'charge_cycle' => '2026-09-08',
        ], $this->auth($subscription))->assertSuccessful();

        $this->assertSame(0, ActivityEvent::query()->where('kind', Timeline::KIND_PLAN_UPDATED)->count());
    }

    public function test_a_cancelled_subscription_says_so_instead_of_leaking_a_developer_error(): void
    {
        /*
         * Cancellation is terminal, so "activate" can never work. The customer used to be
         * shown "Illegal transition on Subscription: cancelled → active".
         */
        $subscription = $this->subscription();
        $subscription->forceFill(['status' => SubscriptionStatus::CANCELLED->value])->save();

        $response = $this->patchJson('/storefront/me/subscription/'.$subscription->id, [
            'subscription_status' => 'active',
        ], $this->auth($subscription))
            ->assertStatus(409)
            ->assertJsonPath('error', 'subscription_cancelled');

        $this->assertStringNotContainsString('Illegal transition', $response->getContent());
        $this->assertSame(SubscriptionStatus::CANCELLED, $subscription->fresh()->status);
    }

    public function test_a_status_change_records_who_did_it_and_from_where(): void
    {
        /*
         * transitionTo defaults to the SYSTEM actor with no reason, so a customer pausing
         * their own subscription read as something the system did for reasons unknown —
         * on the one screen support opens to find out who did what.
         */
        $subscription = $this->subscription();

        $this->patchJson('/storefront/me/subscription/'.$subscription->id, [
            'subscription_status' => 'pending',
        ], $this->auth($subscription))->assertSuccessful();

        $event = ActivityEvent::query()->where('kind', Timeline::KIND_STATUS_CHANGED)->latest('id')->firstOrFail();

        $this->assertSame(Timeline::ACTOR_CUSTOMER, $event->actor);
        $this->assertStringContainsString(__('activity.reason_self_service'), EventPresenter::summarize($event));
        $this->assertStringContainsString(__('subscriptions.status_pending'), EventPresenter::summarize($event));
    }

    public function test_an_old_field_list_row_reads_as_words_rather_than_a_key(): void
    {
        // These rows are permanent — they cannot be improved at source, only rendered better.
        $subscription = $this->subscription();

        $event = new ActivityEvent;
        $event->forceFill([
            'kind' => Timeline::KIND_NOTE,
            'actor' => Timeline::ACTOR_CUSTOMER,
            'subscription_id' => $subscription->id,
            'customer_id' => $subscription->customer_id,
            'details' => ['fields' => ['subscription_status']],
        ])->save();

        $this->assertSame(
            __('activity.sum_fields_changed', ['fields' => __('activity.field_subscription_status')]),
            EventPresenter::summarize($event),
        );
    }

    public function test_a_paused_subscription_can_still_be_restarted(): void
    {
        // The one that MUST keep working: pausing is reversible, and the button that
        // restarts it is the reason a customer pauses instead of cancelling.
        $subscription = $this->subscription(['status' => SubscriptionStatus::PENDING->value]);

        $this->patchJson('/storefront/me/subscription/'.$subscription->id, [
            'subscription_status' => 'active',
        ], $this->auth($subscription))->assertSuccessful();

        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->fresh()->status);
    }
}
