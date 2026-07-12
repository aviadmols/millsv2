<?php

namespace App\Http\Controllers\Api;

use App\Models\QuizDog;
use App\Models\Subscription;
use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Services\Shopify\DraftOrderService;
use App\Modules\MillsSubscriptions\Support\StorefrontPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Throwable;

/**
 * Orders + the billing trigger (SYSTEM-MAP §3.1), at /api/orders/* and the legacy
 * /order/* aliases.
 */
class OrderApiController extends AbstractApiController
{
    public function hello(): JsonResponse
    {
        return response()->json(['message' => 'Hello order!']);
    }

    /** Build/refresh the "upcoming order" draft for a subscription. */
    public function createDraft(Request $request, DraftOrderService $drafts): JsonResponse
    {
        $id = (string) ($request->input('subscriptionId') ?? $request->input('subscription_id') ?? '');
        if ($id === '') {
            return response()->json(['message' => 'subscriptionId is required'], 422);
        }

        try {
            return response()->json($drafts->create($this->resolveSubscription($id)));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()],
                $e->getMessage() === 'shopify_not_connected' ? 503 : 502);
        }
    }

    /**
     * Run one recurring-billing pass now. Charging itself is queued and idempotent
     * (the ledger's unique key makes a double charge impossible), so calling this
     * twice cannot bill anyone twice.
     */
    public function processBilling(): JsonResponse
    {
        SystemLog::info('billing', 'billing pass triggered via API');

        Artisan::call('mills:dispatch-due');
        $output = trim(Artisan::output());

        return response()->json([
            'success' => true,
            'message' => 'Billing pass dispatched.',
            'output' => $output,
        ]);
    }

    /**
     * Shopify `orders/paid`. Idempotent and always 200 — a webhook must never be
     * retried into a loop by an internal error.
     */
    public function webhookOrderPaid(Request $request): JsonResponse
    {
        $order = $request->all();
        $shopifyCustomerId = (string) ($order['customer']['id'] ?? '');

        if ($shopifyCustomerId === '') {
            return response()->json(['ok' => true, 'action' => 'skipped_no_customer']);
        }

        try {
            $customer = $this->resolveCustomer($shopifyCustomerId);
            $orderId = (string) ($order['id'] ?? '');

            // The quiz dog the shopper filled in before checkout, if any.
            $quizDogId = null;
            foreach ((array) ($order['note_attributes'] ?? []) as $attribute) {
                if (($attribute['name'] ?? '') === 'quiz_dog_id') {
                    $quizDogId = (string) ($attribute['value'] ?? '');
                }
            }

            $subscription = Subscription::query()
                ->where('customer_id', $customer->id)
                ->where('original_order_id', $orderId)
                ->first();

            if ($subscription === null) {
                $subscription = new Subscription;
                $subscription->fill([
                    'customer_id' => $customer->id,
                    'payment_state' => \App\Modules\MillsSubscriptions\Enums\PaymentState::NEEDS_CARD_UPDATE->value,
                    'frequency_months' => 1,
                    'original_order_id' => $orderId,
                    'next_charge_at' => now()->addMonth(),
                ]);
                $subscription->forceFill([
                    'status' => \App\Modules\MillsSubscriptions\Enums\SubscriptionStatus::PENDING->value,
                ])->save();
            }

            $dogId = null;
            if ($quizDogId) {
                $quizDog = QuizDog::query()->where('public_id', $quizDogId)->first();
                if ($quizDog?->linkedDog !== null) {
                    $quizDog->linkedDog->forceFill(['subscription_id' => $subscription->id])->save();
                    $dogId = StorefrontPresenter::dogId($quizDog->linkedDog);
                }
            }

            SystemLog::info('webhook', 'orders/paid processed', [
                'order_id' => $orderId,
            ], ['subscription_id' => $subscription->id, 'customer_id' => $customer->id]);

            return response()->json([
                'ok' => true,
                'subscriptionId' => StorefrontPresenter::subscriptionId($subscription),
                'dogId' => $dogId,
            ]);
        } catch (Throwable $e) {
            // Swallow: Shopify must get a 200 or it will retry forever.
            SystemLog::error('webhook', 'orders/paid failed', ['message' => $e->getMessage()]);

            return response()->json(['ok' => true, 'action' => 'error_logged']);
        }
    }
}
