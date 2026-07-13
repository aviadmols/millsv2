<?php

namespace App\Http\Controllers\Api;

use App\Models\Subscription;
use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Exceptions\IllegalTransitionException;
use App\Modules\MillsSubscriptions\Services\Shopify\DraftOrderService;
use App\Modules\MillsSubscriptions\Support\StorefrontPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Subscriptions — the machine-to-machine surface (SYSTEM-MAP §3.1), reachable at
 * both /api/subscriptions/* and the legacy /shopify/subscription/* aliases.
 * Guarded by the API secret. Responses are the v2 subscription shape (the same
 * presenter the theme's personal area consumes).
 */
class SubscriptionApiController extends AbstractApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Subscription::query()->with('dogs');

        if ($request->filled('customerId')) {
            $customer = $this->resolveCustomer((string) $request->query('customerId'));
            $query->where('customer_id', $customer->id);
        }

        if ($request->filled('filter')) {
            $query->where('status', SubscriptionStatus::fromLegacy((string) $request->query('filter'))->value);
        }

        return response()->json($this->present($query->get()));
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(StorefrontPresenter::subscription($this->resolveSubscription($id)));
    }

    /** Subscriptions whose recurring charge falls due today (billing never charges icount). */
    public function dueToday(): JsonResponse
    {
        $subscriptions = Subscription::query()
            ->with('dogs')
            ->where('status', SubscriptionStatus::ACTIVE->value)
            ->where('payment_state', PaymentState::PAYME->value)
            ->whereDate('next_charge_at', '<=', now()->toDateString())
            ->get();

        return response()->json($this->present($subscriptions));
    }

    public function byCustomer(string $customerId): JsonResponse
    {
        $customer = $this->resolveCustomer($customerId);

        return response()->json($this->present($customer->subscriptions()->with('dogs')->get()));
    }

    public function byStatus(string $status): JsonResponse
    {
        $subscriptions = Subscription::query()
            ->with('dogs')
            ->where('status', SubscriptionStatus::fromLegacy($status)->value)
            ->get();

        return response()->json($this->present($subscriptions));
    }

    public function byDraftOrder(string $draftOrderId): JsonResponse
    {
        $subscriptions = Subscription::query()
            ->with('dogs')
            ->where('draft_order_id', $draftOrderId)
            ->get();

        return response()->json($this->present($subscriptions));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer' => ['required', 'string'],
            'dogs' => ['sometimes', 'array'],
            'original_order' => ['sometimes', 'nullable', 'string'],
            'draft_order_id' => ['sometimes', 'nullable', 'string'],
            'charge_cycle' => ['sometimes', 'nullable', 'date'],
            'frequency' => ['sometimes', 'nullable', 'string'],
            'subscription_status' => ['sometimes', 'nullable', 'string'],
        ]);

        $customer = $this->resolveCustomer((string) $data['customer']);

        $subscription = new Subscription;
        $subscription->fill([
            'customer_id' => $customer->id,
            'payment_state' => PaymentState::NEEDS_CARD_UPDATE->value,
            'frequency_months' => str_contains(strtolower((string) ($data['frequency'] ?? '')), '2') ? 2 : 1,
            'next_charge_at' => $data['charge_cycle'] ?? null,
            'original_order_id' => $data['original_order'] ?? null,
            'draft_order_id' => $data['draft_order_id'] ?? null,
        ]);
        $subscription->forceFill([
            'status' => SubscriptionStatus::fromLegacy((string) ($data['subscription_status'] ?? 'pending'))->value,
        ])->save();

        $this->attachDogs($subscription, $this->idList($data, ['dogs']));

        SystemLog::info('api', 'subscription created', [], [
            'subscription_id' => $subscription->id,
            'customer_id' => $customer->id,
        ]);

        return response()->json(StorefrontPresenter::subscription($subscription->fresh()), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $subscription = $this->resolveSubscription($id);

        $data = $request->validate([
            'charge_cycle' => ['sometimes', 'nullable', 'date'],
            'frequency' => ['sometimes', 'nullable', 'string'],
            'draft_order_id' => ['sometimes', 'nullable', 'string'],
            'original_order' => ['sometimes', 'nullable', 'string'],
            'subscription_status' => ['sometimes', 'string'],
        ]);

        if (array_key_exists('frequency', $data)) {
            $subscription->frequency_months = str_contains(strtolower((string) $data['frequency']), '2') ? 2 : 1;
        }
        foreach (['charge_cycle' => 'next_charge_at', 'draft_order_id' => 'draft_order_id', 'original_order' => 'original_order_id'] as $in => $col) {
            if (array_key_exists($in, $data)) {
                $subscription->{$col} = $data[$in];
            }
        }
        $subscription->save();

        if (array_key_exists('subscription_status', $data)) {
            $target = SubscriptionStatus::fromLegacy($data['subscription_status']);

            // v1 rule: a subscription cannot go active with no dogs.
            if ($target === SubscriptionStatus::ACTIVE && $subscription->dogs()->count() === 0) {
                return response()->json(['message' => 'Cannot activate a subscription with no dogs'], 422);
            }

            if ($target !== $subscription->status) {
                try {
                    $subscription->transitionTo($target);
                } catch (IllegalTransitionException $e) {
                    return response()->json(['message' => $e->getMessage()], 422);
                }
            }
        }

        return response()->json(StorefrontPresenter::subscription($subscription->fresh()));
    }

    /** Cancellation is always immediate (no end-of-period mode). */
    public function destroy(string $id): JsonResponse
    {
        $subscription = $this->resolveSubscription($id);

        if ($subscription->status !== SubscriptionStatus::CANCELLED) {
            $subscription->transitionTo(SubscriptionStatus::CANCELLED);
        }
        $subscription->forceFill(['next_charge_at' => null])->save();

        SystemLog::info('api', 'subscription cancelled', [], [
            'subscription_id' => $subscription->id,
            'customer_id' => $subscription->customer_id,
        ]);

        return response()->json(['deletedId' => StorefrontPresenter::subscriptionId($subscription)]);
    }

    /** The products-per-dog map the theme renders. */
    public function products(string $id): JsonResponse
    {
        return response()->json(StorefrontPresenter::subscriptionProducts($this->resolveSubscription($id)));
    }

    public function addDog(Request $request, string $id): JsonResponse
    {
        $subscription = $this->resolveSubscription($id);
        $dogIds = $this->idList($request->all(), ['dogId', 'dogIds']);

        if ($dogIds === []) {
            return response()->json(['message' => 'dogId is required'], 422);
        }

        $this->attachDogs($subscription, $dogIds);

        return response()->json(StorefrontPresenter::subscription($subscription->fresh()));
    }

    public function removeDog(Request $request, string $id): JsonResponse
    {
        $subscription = $this->resolveSubscription($id);
        $dogIds = $this->idList($request->all(), ['dogId', 'dogIds']);

        if ($dogIds === []) {
            return response()->json(['message' => 'dogId is required'], 422);
        }

        foreach ($dogIds as $dogId) {
            $dog = $this->resolveDog($dogId);
            if ((int) $dog->subscription_id === (int) $subscription->id) {
                $dog->forceFill(['subscription_id' => null, 'subscription_status' => null])->save();
            }
        }

        $subscription->refresh();
        if ($subscription->dogs()->count() === 0 && $subscription->status === SubscriptionStatus::ACTIVE) {
            $subscription->transitionTo(SubscriptionStatus::PENDING);
        }

        return response()->json(StorefrontPresenter::subscription($subscription->fresh()));
    }

    public function createDraftOrder(string $id, DraftOrderService $drafts): JsonResponse
    {
        return $this->draft(fn () => $drafts->create($this->resolveSubscription($id)));
    }

    public function updateDraftOrder(string $id, DraftOrderService $drafts): JsonResponse
    {
        return $this->draft(fn () => $drafts->refresh($this->resolveSubscription($id)));
    }

    public function getDraftOrder(string $id, DraftOrderService $drafts): JsonResponse
    {
        return $this->draft(fn () => $drafts->get($this->resolveSubscription($id)));
    }

    /** Create/refresh a subscription from a paid Shopify order (webhook + import path). */
    public function createFromOrder(Request $request): JsonResponse
    {
        $order = $request->all();
        $shopifyCustomerId = (string) ($order['customer']['id'] ?? '');

        if ($shopifyCustomerId === '') {
            return response()->json(['message' => 'customer.id is required'], 422);
        }

        $customer = $this->resolveCustomer($shopifyCustomerId);
        $orderId = (string) ($order['id'] ?? '');

        $subscription = Subscription::query()
            ->where('customer_id', $customer->id)
            ->where('original_order_id', $orderId)
            ->first();

        $action = $subscription ? 'updated' : 'created';

        if ($subscription === null) {
            $subscription = new Subscription;
            $subscription->fill([
                'customer_id' => $customer->id,
                'payment_state' => PaymentState::NEEDS_CARD_UPDATE->value,
                'frequency_months' => 1,
                'original_order_id' => $orderId,
                'next_charge_at' => now()->addMonth(),
            ]);
            $subscription->forceFill(['status' => SubscriptionStatus::PENDING->value])->save();
        }

        SystemLog::info('api', "subscription {$action} from order", [
            'order_id' => $orderId,
        ], ['subscription_id' => $subscription->id, 'customer_id' => $customer->id]);

        return response()->json(
            StorefrontPresenter::subscription($subscription->fresh()) + ['_import_action' => $action],
        );
    }

    // --- helpers ---

    /** @param iterable<Subscription> $subscriptions */
    private function present(iterable $subscriptions): array
    {
        $out = [];
        foreach ($subscriptions as $subscription) {
            $out[] = StorefrontPresenter::subscription($subscription);
        }

        return $out;
    }

    /** @param list<string> $dogIds */
    private function attachDogs(Subscription $subscription, array $dogIds): void
    {
        foreach ($dogIds as $dogId) {
            $dog = $this->resolveDog($dogId);

            if ((int) $dog->customer_id !== (int) $subscription->customer_id) {
                continue;   // never move a dog across customers
            }

            $dog->forceFill([
                'subscription_id' => $subscription->id,
                'subscription_status' => 'active',
            ])->save();
        }
    }

    private function draft(callable $operation): JsonResponse
    {
        try {
            return response()->json($operation());
        } catch (RuntimeException $e) {
            $status = $e->getMessage() === 'shopify_not_connected' ? 503 : 502;

            return response()->json(['message' => $e->getMessage()], $status);
        }
    }
}
