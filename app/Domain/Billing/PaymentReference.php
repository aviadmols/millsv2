<?php

namespace App\Domain\Billing;

/**
 * The idempotency key, in a shape the gateway will accept.
 *
 * Our keys look like `recurring:7:2026-07-13`. PayMe's `transaction_id` is a merchant
 * reference it uses to DEDUPE — send the same one twice and it collapses them into one
 * sale — but colons are not safe to send. This maps the key onto a stable, safe id.
 *
 * The mapping must be deterministic: the retry of a cycle has to produce the SAME
 * reference as the original attempt, or PayMe cannot recognise it as the same charge and
 * the customer is billed twice. That property is the whole point of this class.
 *
 * It is also reversible enough to be traced: the ledger stores the original key, and
 * `for()` on that key always yields the reference PayMe saw.
 */
final class PaymentReference
{
    /** PayMe's field is short — keep well inside it. */
    private const MAX_LENGTH = 40;

    public static function for(string $idempotencyKey): string
    {
        // `recurring:7:2026-07-13` → `recurring-7-2026-07-13`
        $safe = preg_replace('/[^A-Za-z0-9\-_]+/', '-', trim($idempotencyKey)) ?? '';
        $safe = trim($safe, '-');

        if ($safe === '') {
            $safe = 'charge';
        }

        if (strlen($safe) <= self::MAX_LENGTH) {
            return $safe;
        }

        // Too long: keep a readable head and make the tail unique-by-construction, so the
        // result is still deterministic for the same key.
        $hash = substr(hash('sha256', $idempotencyKey), 0, 12);

        return substr($safe, 0, self::MAX_LENGTH - 13).'-'.$hash;
    }
}
