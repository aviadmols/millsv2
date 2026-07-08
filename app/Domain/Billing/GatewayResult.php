<?php

namespace App\Domain\Billing;

/**
 * Normalized result of a gateway charge attempt. The gateway boundary
 * (PaymeGateway) always returns one of these — the ChargeOrchestrator never
 * inspects raw PayMe payloads. `raw` is already masked (no card data / no
 * buyer_key echoed) before it reaches here.
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
    ) {}

    /** @param array<string, mixed> $raw */
    public static function success(string $transactionId, array $raw = []): self
    {
        return new self(success: true, transactionId: $transactionId, raw: $raw);
    }

    /** @param array<string, mixed> $raw */
    public static function failure(string $code, string $message, array $raw = []): self
    {
        return new self(success: false, failureCode: $code, failureMessage: $message, raw: $raw);
    }
}
