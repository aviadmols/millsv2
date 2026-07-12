<?php

namespace App\Http\Controllers\Storefront;

use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Exceptions\IllegalTransitionException;
use App\Modules\MillsSubscriptions\Support\StorefrontPresenter;
use App\Modules\MillsSubscriptions\Support\Timeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer-driven subscription writes (SYSTEM-MAP §3.3). Every write is
 * ownership-checked and blocked by the card-update wall, mirroring v1.
 *
 * Status changes go through the guarded state machine (transitionTo) — a raw
 * status write is a violation (CLAUDE.md). Cancellation is always immediate.
 */
class StorefrontSubscriptionController extends AbstractStorefrontController
{
    public function update(Request $request, string $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $subscription = $this->findOwnedSubscription($customer, $id);
        $this->guardCardUpdate($subscription);

        $data = $request->validate([
            'frequency' => ['sometimes', 'string', 'max:64'],
            'charge_cycle' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'subscription_status' => ['sometimes', 'string', 'in:active,pending,disable,paused'],
        ]);

        if ($data === []) {
            return $this->fail('no_fields', 'לא נשלחו שדות לעדכון.', 422);
        }

        if (array_key_exists('frequency', $data)) {
            // v1 sends a free-text frequency ("Monthly" / "Every 2 Months").
            $subscription->frequency_months = str_contains(strtolower($data['frequency']), '2') ? 2 : 1;
        }

        if (array_key_exists('charge_cycle', $data)) {
            $subscription->next_charge_at = $data['charge_cycle'];
        }

        $subscription->save();

        if (array_key_exists('subscription_status', $data)) {
            $target = SubscriptionStatus::fromLegacy($data['subscription_status']);

            if ($target !== $subscription->status) {
                try {
                    $subscription->transitionTo($target);
                } catch (IllegalTransitionException $e) {
                    return $this->fail('illegal_transition', $e->getMessage(), 422);
                }
            }
        }

        SystemLog::info('storefront', 'subscription updated by customer', [
            'fields' => array_keys($data),
        ], ['subscription_id' => $subscription->id, 'customer_id' => $customer->id]);

        Timeline::record(Timeline::KIND_NOTE, ['fields' => array_keys($data)], $subscription->id, $customer->id, Timeline::ACTOR_CUSTOMER);

        return $this->ok(['subscription' => StorefrontPresenter::subscription($subscription->fresh())]);
    }

    public function addDog(Request $request, string $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $subscription = $this->findOwnedSubscription($customer, $id);
        $this->guardCardUpdate($subscription);

        $dogIds = $this->idList($request, ['dogId', 'dogIds']);
        if ($dogIds === []) {
            return $this->fail('dog_id_required', 'נדרש מזהה כלב.', 422);
        }

        foreach ($dogIds as $dogId) {
            $dog = $this->findOwnedDog($customer, $dogId);   // 404 if not the customer's
            $dog->subscription_id = $subscription->id;
            $dog->subscription_status = 'active';
            $dog->save();
        }

        SystemLog::info('storefront', 'dogs added to subscription', [
            'dogs' => $dogIds,
        ], ['subscription_id' => $subscription->id, 'customer_id' => $customer->id]);

        return $this->ok(['subscription' => StorefrontPresenter::subscription($subscription->fresh())]);
    }

    public function removeDog(Request $request, string $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $subscription = $this->findOwnedSubscription($customer, $id);
        $this->guardCardUpdate($subscription);

        $dogIds = $this->idList($request, ['dogId', 'dogIds']);
        if ($dogIds === []) {
            return $this->fail('dog_id_required', 'נדרש מזהה כלב.', 422);
        }

        foreach ($dogIds as $dogId) {
            $dog = $this->findOwnedDog($customer, $dogId);
            if ((int) $dog->subscription_id !== (int) $subscription->id) {
                continue;
            }
            $dog->subscription_id = null;
            $dog->subscription_status = null;
            $dog->save();
        }

        // v1 rule: a subscription with no dogs left falls back to `pending`.
        $subscription->refresh();
        if ($subscription->dogs()->count() === 0 && $subscription->status === SubscriptionStatus::ACTIVE) {
            $subscription->transitionTo(SubscriptionStatus::PENDING);
        }

        SystemLog::info('storefront', 'dogs removed from subscription', [
            'dogs' => $dogIds,
        ], ['subscription_id' => $subscription->id, 'customer_id' => $customer->id]);

        return $this->ok(['subscription' => StorefrontPresenter::subscription($subscription->fresh())]);
    }
}
