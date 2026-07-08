<?php

namespace App\Support;

/**
 * Single source of truth for redacting payment/auth secrets out of structured
 * context before it is persisted to payment_ledger.raw_response_masked or logged
 * (CLAUDE.md law #7). Never let a buyer_key / card / token reach storage raw.
 */
final class PaymentContextMasker
{
    /** @var list<string> case-insensitive substring match on key names */
    private const SENSITIVE_SUBSTRINGS = [
        'card', 'cvv', 'cvc', 'pan', 'token', 'password',
        'authorization', 'buyer_key', 'buyerkey', 'secret',
    ];

    /** @var list<string> exact key names that always redact */
    private const SENSITIVE_EXACT = ['transaction_id', 'transactionid'];

    /**
     * @param  array<string, mixed>|null  $context
     * @return array<string, mixed>
     */
    public static function mask(?array $context): array
    {
        if (! is_array($context) || $context === []) {
            return $context ?? [];
        }

        $masked = [];
        foreach ($context as $key => $value) {
            $keyStr = (string) $key;

            if (self::isSensitive($keyStr)) {
                $masked[$keyStr] = '[REDACTED]';

                continue;
            }

            $masked[$keyStr] = is_array($value) ? self::mask($value) : $value;
        }

        return $masked;
    }

    private static function isSensitive(string $key): bool
    {
        $lower = strtolower($key);

        if (in_array($lower, self::SENSITIVE_EXACT, true)) {
            return true;
        }

        foreach (self::SENSITIVE_SUBSTRINGS as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }
}
