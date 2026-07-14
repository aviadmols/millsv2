<?php

namespace App\Modules\MillsSubscriptions\Services;

use App\Domain\Billing\IdempotencyKey;
use App\Domain\Billing\Ledger;
use App\Models\Customer;
use App\Models\PaymentLedger;
use App\Models\PaymentMethod;
use App\Models\Subscription;
use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Enums\LedgerStatus;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Services\PayMe\PaymeClient;
use App\Modules\MillsSubscriptions\Support\Timeline;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The card-update flow (SYSTEM-MAP §3.4).
 *
 * 1. createSession() opens a PayMe hosted sale that captures a buyer_key and stashes a
 *    single-use session (UUID, 15-minute TTL) in the cache.
 * 2. consume() exchanges the PayMe sale for the buyer_key, stores it as the customer's
 *    active PaymentMethod, and lifts the card-update wall.
 *
 * Frozen v1 behaviour preserved: the wall is lifted for the whole customer — the target
 * subscription AND every sibling still marked needs_card_update — because one card backs all
 * of them (the 2026-06 fix).
 *
 * TWO THINGS THE CACHE ALONE CANNOT DO, which is why every session also opens a ledger row:
 *
 *  - The verification sale is REAL MONEY on a real card (PayMe will not tokenise for free).
 *    Money that moves without a ledger row is money the system cannot account for.
 *  - The buyer_key is only fetched when the customer's BROWSER comes back. Close the tab and
 *    PayMe has taken the money and captured the card while we hold nothing — the customer
 *    stays blocked from being charged and no one ever finds out. The pending ledger row
 *    outlives the cache and is what `mills:reconcile-card-updates` sweeps to recover it.
 */
class CardUpdateService
{
    private const SESSION_TTL_SECONDS = 900;   // 15 minutes, single use

    public function __construct(private readonly PaymeClient $payme) {}

    /**
     * @param  string  $actor  who is doing this — Timeline::ACTOR_CUSTOMER, or
     *                         Timeline::admin($id) when staff act on the customer's behalf.
     * @return array<string, mixed> the frozen session envelope the theme expects
     */
    public function createSession(
        Customer $customer,
        ?Subscription $subscription = null,
        string $actor = Timeline::ACTOR_CUSTOMER,
    ): array {
        $subscription ??= $this->pickSubscription($customer);

        if ($subscription === null) {
            throw new RuntimeException('no_subscription_found');
        }

        // Fail before the ledger row, not after: a misconfigured environment must not leave
        // dangling `pending` rows for the reconciler to chew on forever.
        if (! $this->payme->isConfigured()) {
            throw new RuntimeException('payme_not_configured');
        }

        $sessionId = (string) Str::uuid();
        $callbackUrl = route('storefront.payment-method.payme-callback', ['session_id' => $sessionId]);
        $agorot = $this->verificationAgorot();

        $ledger = $this->openLedger($sessionId, $customer, $subscription, $agorot, $actor);

        try {
            $sale = $this->payme->createBuyerCaptureSale([
                'price' => $agorot,
                'productName' => 'בדיקת כרטיס',
                'callbackUrl' => $callbackUrl,
                'transactionId' => $sessionId,
            ]);
        } catch (Throwable $e) {
            $this->closeLedger($ledger, LedgerStatus::FAILED, ['failure_message' => $e->getMessage()]);

            throw new RuntimeException('payme_session_failed');
        }

        $saleId = (string) ($sale['payme_sale_id'] ?? $sale['sale_id'] ?? '');
        $hostedUrl = (string) ($sale['sale_url'] ?? $sale['payme_sale_url'] ?? '');

        if ($saleId === '' || $hostedUrl === '') {
            SystemLog::error('billing', 'PayMe did not return a hosted sale for the card update', [
                'response_keys' => array_keys($sale),
            ], ['subscription_id' => $subscription->id, 'customer_id' => $customer->id]);

            $this->closeLedger($ledger, LedgerStatus::FAILED, ['failure_code' => 'no_hosted_sale']);

            throw new RuntimeException('payme_session_failed');
        }

        // The durable trace. Without the sale id on the row, a card captured at PayMe but
        // never returned to us could never be found again.
        $ledger?->forceFill(['payme_transaction_id' => $saleId])->save();

        Cache::put($this->cacheKey($sessionId), [
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'payme_sale_id' => $saleId,
            'actor' => $actor,
        ], self::SESSION_TTL_SECONDS);

        SystemLog::info('billing', 'card-update session opened', [
            'session_id' => $sessionId,
            'verification_agorot' => $agorot,
            'actor' => $actor,
        ], ['subscription_id' => $subscription->id, 'customer_id' => $customer->id]);

        // The envelope is frozen — the live theme reads these exact keys.
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
     * Exchange the session for a buyer_key and lift the wall.
     *
     * @return array<string, mixed>
     */
    public function consume(string $sessionId): array
    {
        $session = $this->resolveSession($sessionId);

        if ($session === null) {
            throw new RuntimeException('session_expired');
        }

        if ($session['already_done'] ?? false) {
            return ['ok' => true, 'subscription_id' => $session['subscription_id'], 'subscriptions_unblocked' => 0];
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

        $this->settleLedger($sessionId);

        $actor = (string) ($session['actor'] ?? Timeline::ACTOR_CUSTOMER);

        SystemLog::info('billing', 'card updated — wall lifted', [
            'subscriptions_unblocked' => $lifted,
            'actor' => $actor,
        ], ['subscription_id' => $session['subscription_id'], 'customer_id' => $customer->id]);

        Timeline::record(
            Timeline::KIND_CARD_UPDATED,
            ['subscriptions_unblocked' => $lifted],
            $session['subscription_id'],
            $customer->id,
            // NOT hardcoded to the customer any more. An admin who updates a card on the
            // phone must not appear in the audit trail as the customer doing it themselves.
            $actor,
        );

        return ['ok' => true, 'subscription_id' => $session['subscription_id'], 'subscriptions_unblocked' => $lifted];
    }

    /**
     * The session, from the cache if it is still there and from the LEDGER if it is not.
     *
     * The cache lives 15 minutes. A card-update link sent to a customer by SMS may be opened
     * an hour later — and without this fallback that customer would enter their card, be
     * charged, and be told the link had expired while we threw the token away.
     *
     * @return array<string, mixed>|null
     */
    private function resolveSession(string $sessionId): ?array
    {
        $cached = Cache::pull($this->cacheKey($sessionId));   // single use

        if (is_array($cached)) {
            return $cached;
        }

        $ledger = Ledger::find(IdempotencyKey::cardUpdate($sessionId));

        if ($ledger === null) {
            return null;
        }

        // Already settled — a double-submit, not an error. Say so rather than re-charging
        // the flow or shouting at a customer who did nothing wrong.
        if ($ledger->status === LedgerStatus::SUCCEEDED) {
            return [
                'already_done' => true,
                'subscription_id' => $ledger->subscription_id,
                'customer_id' => $ledger->customer_id,
            ];
        }

        if ($ledger->status !== LedgerStatus::PENDING) {
            return null;
        }

        return [
            'customer_id' => $ledger->customer_id,
            'subscription_id' => $ledger->subscription_id,
            'payme_sale_id' => (string) $ledger->payme_transaction_id,
            'actor' => (string) ($ledger->meta['initiated_by'] ?? Timeline::ACTOR_CUSTOMER),
        ];
    }

    /** The verification charge, in agorot. 0 ⇒ no charge, and therefore no ledger row. */
    private function verificationAgorot(): int
    {
        return max(0, (int) config('payme.card_update_verification_agorot', 10));
    }

    private function openLedger(
        string $sessionId,
        Customer $customer,
        Subscription $subscription,
        int $agorot,
        string $actor,
    ): ?PaymentLedger {
        if ($agorot === 0) {
            return null;   // nothing was charged, so there is no money to account for
        }

        return Ledger::open(
            IdempotencyKey::CONTEXT_CARD_UPDATE,
            IdempotencyKey::cardUpdate($sessionId),
            $agorot / 100,
            'ILS',
            [
                'customer_id' => $customer->id,
                'subscription_id' => $subscription->id,
                'meta' => ['initiated_by' => $actor, 'session_id' => $sessionId],
            ],
        );
    }

    /** @param  array<string, mixed>  $patch */
    private function closeLedger(?PaymentLedger $ledger, LedgerStatus $to, array $patch = []): void
    {
        if ($ledger === null) {
            return;
        }

        Ledger::transition($ledger, $to, $patch);
    }

    /** The card is ours; the ₪0.10 was really taken. Both facts belong on the same row. */
    private function settleLedger(string $sessionId): void
    {
        $ledger = Ledger::find(IdempotencyKey::cardUpdate($sessionId));

        if ($ledger === null || $ledger->status !== LedgerStatus::PENDING) {
            return;
        }

        Ledger::transition($ledger, LedgerStatus::SUCCEEDED, ['executed_at' => now()]);
    }

    public function storeBuyerKey(Customer $customer, string $buyerKey, string $maskedCard): void
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
    public function liftCardUpdateWall(Customer $customer): int
    {
        return $customer->subscriptions()
            ->where('payment_state', PaymentState::NEEDS_CARD_UPDATE->value)
            ->update(['payment_state' => PaymentState::PAYME->value]);
    }

    private function pickSubscription(Customer $customer): ?Subscription
    {
        return $customer->subscriptions()
            ->orderByRaw('CASE WHEN payment_state = ? THEN 0 ELSE 1 END', [PaymentState::NEEDS_CARD_UPDATE->value])
            ->orderByDesc('id')
            ->first();
    }

    private function cacheKey(string $sessionId): string
    {
        return 'card_update_session:'.$sessionId;
    }
}
