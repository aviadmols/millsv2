<?php

namespace App\Modules\MillsSubscriptions\Services;

use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Subscription;
use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Services\PayMe\PaymeClient;
use App\Modules\MillsSubscriptions\Support\Timeline;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The card-update flow (SYSTEM-MAP §3.4).
 *
 * 1. createSession() opens a PayMe hosted sale that captures a buyer_key and
 *    stashes a single-use session (UUID, 15-minute TTL) in the cache.
 * 2. consume() exchanges the PayMe sale for the buyer_key, stores it as the
 *    customer's active PaymentMethod, and lifts the card-update wall.
 *
 * Frozen v1 behaviour preserved: the wall is lifted for the whole customer — the
 * target subscription AND every sibling still marked needs_card_update — because
 * one card backs all of them (the 2026-06 fix).
 */
class CardUpdateService
{
    private const SESSION_TTL_SECONDS = 900;   // 15 minutes, single use

    public function __construct(private readonly PaymeClient $payme) {}

    /**
     * @return array<string, mixed> the frozen session envelope the theme expects
     */
    public function createSession(Customer $customer, ?Subscription $subscription = null): array
    {
        $subscription ??= $this->pickSubscription($customer);

        if ($subscription === null) {
            throw new RuntimeException('no_subscription_found');
        }

        $sessionId = (string) Str::uuid();
        $callbackUrl = route('storefront.payment-method.payme-callback', ['session_id' => $sessionId]);

        $sale = $this->payme->createBuyerCaptureSale([
            'callbackUrl' => $callbackUrl,
            'productName' => 'עדכון אמצעי תשלום',
        ]);

        $saleId = (string) ($sale['payme_sale_id'] ?? $sale['sale_id'] ?? '');
        $hostedUrl = (string) ($sale['sale_url'] ?? $sale['payme_sale_url'] ?? '');

        if ($saleId === '' || $hostedUrl === '') {
            SystemLog::error('billing', 'PayMe did not return a hosted sale for the card update', [
                'response_keys' => array_keys($sale),
            ], ['subscription_id' => $subscription->id, 'customer_id' => $customer->id]);

            throw new RuntimeException('payme_session_failed');
        }

        Cache::put($this->cacheKey($sessionId), [
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'payme_sale_id' => $saleId,
        ], self::SESSION_TTL_SECONDS);

        SystemLog::info('billing', 'card-update session opened', [
            'session_id' => $sessionId,
        ], ['subscription_id' => $subscription->id, 'customer_id' => $customer->id]);

        return [
            'session_id' => $sessionId,
            'mode' => 'legacy_hosted_page',
            'hosted_url' => $hostedUrl,
            'return_url' => $callbackUrl,
            'subscription_id' => $subscription->id,
            'expires_in_seconds' => self::SESSION_TTL_SECONDS,
        ];
    }

    /**
     * Single-use: exchange the session for a buyer_key and lift the wall.
     *
     * @return array<string, mixed>
     */
    public function consume(string $sessionId): array
    {
        $session = Cache::pull($this->cacheKey($sessionId));   // single use
        if (! is_array($session)) {
            throw new RuntimeException('session_expired');
        }

        $customer = Customer::query()->find($session['customer_id']);
        if ($customer === null) {
            throw new RuntimeException('customer_not_found');
        }

        $result = $this->payme->getBuyerKey((string) $session['payme_sale_id']);
        $buyerKey = (string) ($result['buyer_key'] ?? '');

        if ($buyerKey === '') {
            SystemLog::error('billing', 'card update failed — PayMe returned no buyer_key', [
                'session_id' => $sessionId,
            ], ['customer_id' => $customer->id]);

            throw new RuntimeException('buyer_key_missing');
        }

        $this->storeBuyerKey($customer, $buyerKey, (string) ($result['masked_card'] ?? $result['card_mask'] ?? ''));
        $lifted = $this->liftCardUpdateWall($customer);

        SystemLog::info('billing', 'card updated — wall lifted', [
            'subscriptions_unblocked' => $lifted,
        ], ['subscription_id' => $session['subscription_id'], 'customer_id' => $customer->id]);

        Timeline::record(
            Timeline::KIND_CARD_UPDATED,
            ['subscriptions_unblocked' => $lifted],
            $session['subscription_id'],
            $customer->id,
            Timeline::ACTOR_CUSTOMER,
        );

        return ['ok' => true, 'subscription_id' => $session['subscription_id'], 'subscriptions_unblocked' => $lifted];
    }

    private function storeBuyerKey(Customer $customer, string $buyerKey, string $maskedCard): void
    {
        // One active card per customer — retire the previous one.
        $customer->paymentMethods()->where('is_active', true)->update(['is_active' => false]);

        PaymentMethod::query()->create([
            'customer_id' => $customer->id,
            'gateway' => 'payme',
            'buyer_key' => $buyerKey,
            'masked_card' => $maskedCard !== '' ? $maskedCard : null,
            'is_active' => true,
            'source' => 'card_update',
            'captured_at' => now(),
        ]);
    }

    /**
     * Lift the wall on EVERY subscription of this customer that still needs a card
     * — one card backs them all.
     */
    private function liftCardUpdateWall(Customer $customer): int
    {
        return $customer->subscriptions()
            ->where('payment_state', PaymentState::NEEDS_CARD_UPDATE->value)
            ->update(['payment_state' => PaymentState::PAYME->value]);
    }

    private function pickSubscription(Customer $customer): ?Subscription
    {
        return $customer->subscriptions()
            ->orderByRaw("CASE WHEN payment_state = ? THEN 0 ELSE 1 END", [PaymentState::NEEDS_CARD_UPDATE->value])
            ->orderByDesc('id')
            ->first();
    }

    private function cacheKey(string $sessionId): string
    {
        return 'card_update_session:'.$sessionId;
    }
}
