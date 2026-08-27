<?php

namespace App\Modules\MillsSubscriptions\Services;

use App\Models\Subscription;
use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Services\PayMe\PaymeClient;
use App\Modules\MillsSubscriptions\Services\Shopify\ShopifyAdminClient;
use App\Modules\MillsSubscriptions\Support\Timeline;
use Throwable;

/**
 * Pull the reusable PayMe buyer_key out of the checkout the customer ALREADY paid.
 *
 * A subscription born from a paid order used to start behind the card-update wall on
 * principle — "we hold no token for the next cycle". But the customer just typed their
 * card into PayMe at checkout; sending them a link to type it again is asking for what
 * we are already holding. v1 knew this (order.service.js createPayment): the order's
 * successful transaction carries PayMe's payment id, get-transactions turns that into
 * the sale, and get-buyer-key on THAT sale is permitted — it is the API-generated-sale
 * flavour of get-buyer-key that this PayMe account refuses, not this one, which v1
 * used in production for years.
 *
 * Strictly best-effort: any failure leaves the wall exactly where it was, and the
 * fifteen-minute ingest sweep retries on every pass until it succeeds — so a PayMe
 * hiccup at order time costs minutes, not a customer interaction.
 */
class CheckoutCardCapture
{
    private const ORDER_TRANSACTIONS_QUERY = <<<'GQL'
    query($id: ID!) {
      order(id: $id) {
        transactions {
          status
          kind
          paymentId
          gateway
        }
      }
    }
    GQL;

    /**
     * Gateways that can never hand us a token to charge the next cycle with.
     *
     * PayPal is the one that matters. Shopify's PayPal checkout leaves the merchant no
     * reusable billing agreement — the consent is PayPal's with Shopify, not with us —
     * and blocking it at checkout needs a Shopify Function, which for a custom app needs
     * Shopify Plus (27 Aug: "Shop must be on a Shopify Plus plan to activate functions
     * from a custom app"). So these orders WILL keep arriving. The least the system can
     * do is name the reason, instead of leaving a generic failure for someone to
     * investigate as though something had broken.
     */
    private const TOKENLESS_GATEWAYS = ['paypal', 'amazon_payments', 'gift_card'];

    public function __construct(
        private readonly ShopifyAdminClient $shopify,
        private readonly PaymeClient $payme,
        private readonly CardUpdateService $cards,
    ) {}

    /**
     * Try to lift the wall from the order's own payment. True when the wall came down.
     */
    public function attempt(Subscription $subscription): bool
    {
        if ($subscription->payment_state !== PaymentState::NEEDS_CARD_UPDATE) {
            return false;   // nothing to lift
        }

        $customer = $subscription->customer;
        $orderId = (string) ($subscription->original_order_id ?? '');

        if ($customer === null || $orderId === '') {
            return false;
        }

        // The customer may have updated a card since (portal, admin) — never overwrite
        // a card somebody chose with one recovered from an old order.
        if ($customer->activePaymentMethod() !== null) {
            return false;
        }

        if (! $this->shopify->isConnected() || ! $this->payme->isConfigured()) {
            return false;
        }

        try {
            // Every give-up names its step in system_logs. A silent false here meant a
            // walled subscription with NOTHING on the admin's screen to say why — the
            // exact opacity this whole system exists to prevent.
            ['payment_id' => $paymentId, 'gateway' => $gateway] = $this->successfulPayment($orderId);

            // Say it once, plainly, and stop asking PayMe about a payment that was never
            // theirs: this is an ordinary outcome, not a fault to be investigated.
            if ($this->isTokenless($gateway)) {
                return $this->giveUp(
                    $subscription,
                    $orderId,
                    "the order was paid with {$gateway}, which leaves no reusable token — the customer has to enter a card for the recurring charges",
                );
            }

            if ($paymentId === '') {
                return $this->giveUp($subscription, $orderId, 'Shopify returned no successful transaction with a payment id for this order');
            }

            $transaction = $this->payme->getTransaction($paymentId);
            $salePaymeId = (string) (data_get($transaction, 'items.0.sale_payme_id') ?? '');
            if ($salePaymeId === '') {
                return $this->giveUp($subscription, $orderId, 'PayMe get-transactions returned no sale for payment '.$paymentId);
            }

            $buyer = $this->payme->getBuyerKey($salePaymeId);
            $buyerKey = (string) ($buyer['buyer_key'] ?? '');
            if ($buyerKey === '') {
                return $this->giveUp($subscription, $orderId, 'PayMe get-buyer-key returned no buyer_key for sale '.$salePaymeId);
            }

            $this->cards->storeBuyerKey(
                $customer,
                $buyerKey,
                (string) ($buyer['masked_card'] ?? $buyer['card_mask'] ?? $buyer['buyer_card_mask'] ?? ''),
                source: 'checkout',
            );
            $lifted = $this->cards->liftCardUpdateWall($customer);

            /*
             * PENDING → ACTIVE, through the guarded machine so it lands on the timeline.
             *
             * Lifting the wall alone was not enough: the dispatcher only ever charges
             * ACTIVE subscriptions, so a paid checkout whose card was captured would have
             * sat "ממתין" forever — billable in every respect except the one the biller
             * reads. Everything ACTIVE requires is here: a paid order, a dog with its
             * flavors, an amount, a date, and now a card.
             */
            if ($subscription->currentStatus() === SubscriptionStatus::PENDING) {
                $subscription->transitionTo(
                    SubscriptionStatus::ACTIVE,
                    ['reason' => 'checkout card captured'],
                );
            }

            SystemLog::info('billing', 'card captured from the checkout payment — wall lifted', [
                'order_id' => $orderId,
                'subscriptions_unblocked' => $lifted,
                'credential_fingerprint' => hash('sha256', $buyerKey),
            ], ['subscription_id' => $subscription->id, 'customer_id' => $customer->id]);

            Timeline::record(
                Timeline::KIND_CARD_UPDATED,
                ['subscriptions_unblocked' => $lifted, 'source' => 'checkout'],
                $subscription->id,
                $customer->id,
                Timeline::ACTOR_SYSTEM,
            );

            return true;
        } catch (Throwable $e) {
            // The wall stays up and the sweep will try again — say why, where the admin looks.
            SystemLog::warning('billing', 'could not capture the card from the checkout payment', [
                'order_id' => $orderId,
                'message' => $e->getMessage(),
            ], ['subscription_id' => $subscription->id, 'customer_id' => $customer->id]);

            return false;
        }
    }

    /** Record WHY the capture stopped, where the admin will look, and give up this pass. */
    private function giveUp(Subscription $subscription, string $orderId, string $reason): bool
    {
        SystemLog::warning('billing', 'could not capture the card from the checkout payment', [
            'order_id' => $orderId,
            'message' => $reason,
        ], ['subscription_id' => $subscription->id, 'customer_id' => $subscription->customer_id]);

        return false;
    }

    /** The PayMe payment id on the order's successful transaction, from Shopify. */
    /**
     * The order's successful payment: its PayMe id, and the gateway that took the money.
     *
     * The gateway is read so a give-up can name itself. Without it every non-PayMe
     * checkout produced the same "PayMe returned no sale" line, which reads like a PayMe
     * outage rather than the truth: this order was never PayMe's to begin with.
     *
     * @return array{payment_id: string, gateway: string}
     */
    private function successfulPayment(string $orderId): array
    {
        $result = $this->shopify->graphql(self::ORDER_TRANSACTIONS_QUERY, [
            'id' => 'gid://shopify/Order/'.$orderId,
        ]);

        foreach ((array) data_get($result, 'data.order.transactions', []) as $transaction) {
            $transaction = (array) $transaction;

            if (strtoupper((string) ($transaction['status'] ?? '')) === 'SUCCESS'
                && in_array(strtoupper((string) ($transaction['kind'] ?? '')), ['SALE', 'CAPTURE'], true)) {
                return [
                    'payment_id' => (string) ($transaction['paymentId'] ?? ''),
                    'gateway' => strtolower(trim((string) ($transaction['gateway'] ?? ''))),
                ];
            }
        }

        return ['payment_id' => '', 'gateway' => ''];
    }

    /** True when this gateway can never yield a reusable token, whatever we ask it. */
    private function isTokenless(string $gateway): bool
    {
        foreach (self::TOKENLESS_GATEWAYS as $needle) {
            if ($gateway !== '' && str_contains($gateway, $needle)) {
                return true;
            }
        }

        return false;
    }
}
