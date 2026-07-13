<?php

namespace App\Domain\Billing\Contracts;

use App\Domain\Billing\GatewayResult;

/**
 * The single seam between the billing engine and the payment provider. PayMe is
 * the only implementation today (App\Modules\MillsSubscriptions\Services\PayMe\
 * PayMeGateway); anything the orchestrator needs from a provider goes through
 * this contract so the engine never depends on PayMe specifics.
 */
interface PaymentGateway
{
    /**
     * Charge a saved payment reference (PayMe buyer_key). The money-truth ledger
     * row is opened by the caller BEFORE this is invoked; this method only talks
     * to the gateway and returns a normalized result.
     *
     * @param  string  $reference  the saved buyer_key
     * @param  int  $amountAgorot  amount in agorot (ILS minor units)
     * @param  string  $idempotencyKey  deterministic key (sent as Idempotency-Key)
     * @param  array<string, mixed>  $opts  optional metadata (order name, description)
     */
    public function chargeWithReference(
        string $reference,
        int $amountAgorot,
        string $idempotencyKey,
        array $opts = [],
    ): GatewayResult;

    /**
     * Ask the gateway what actually happened to a charge whose answer we never got.
     *
     * This is the ONLY way out of an ambiguous outcome. When a charge times out, the card
     * may or may not have been debited — and the one thing we must never do is assume. The
     * idempotency key was sent to the gateway as its own reference, so the charge can be
     * looked up by it rather than guessed at.
     *
     * Returns success (it went through), failure (it did not), or ambiguous again (the
     * gateway still cannot tell us — in which case the money stays in limbo and a human
     * must look, which is strictly better than charging twice).
     */
    public function lookup(string $idempotencyKey): GatewayResult;
}
