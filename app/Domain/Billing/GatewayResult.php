<?php

namespace App\Domain\Billing;

/**
 * Normalized result of a gateway charge attempt.
 *
 * There are THREE outcomes, not two. The third is the one that matters:
 *
 *   success    — the gateway said yes. The money moved.
 *   failure    — the gateway said no. The money did NOT move. Safe to retry.
 *   AMBIGUOUS  — the gateway said nothing. A timeout, a dropped connection, a
 *                process killed mid-call. The card may or may not have been
 *                debited, and WE DO NOT KNOW WHICH.
 *
 * Collapsing "ambiguous" into "failure" is how a customer gets charged twice: PayMe
 * debits the card, the response is lost, we record a decline, and four hours later
 * we cheerfully charge them again. An ambiguous result must never be retried — it
 * must be RECONCILED against the gateway first.
 *
 * `raw` is already masked (no card data, no buyer_key echoed) before it reaches here.
 */
final class GatewayResult
{
    /**
     * @param  array<string, mixed>  $raw  masked gateway response for the ledger
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?string $transactionId = null,
        public readonly ?string $failureCode = null,
        public readonly ?string $failureMessage = null,
        public readonly array $raw = [],
        /** True when we do not know whether the money moved. NOT a failure. */
        public readonly bool $ambiguous = false,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function success(string $transactionId, array $raw = []): self
    {
        return new self(success: true, transactionId: $transactionId, raw: $raw);
    }

    /**
     * The gateway DECLINED. The money did not move, so a retry is safe.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function failure(string $code, string $message, array $raw = []): self
    {
        return new self(success: false, failureCode: $code, failureMessage: $message, raw: $raw);
    }

    /**
     * The gateway's answer never arrived. The card MAY have been charged.
     *
     * The caller must leave the ledger row open and reconcile — never retry blind.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function ambiguous(string $message, array $raw = []): self
    {
        return new self(
            success: false,
            failureCode: 'ambiguous',
            failureMessage: $message,
            raw: $raw,
            ambiguous: true,
        );
    }
}
