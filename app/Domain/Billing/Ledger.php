<?php

namespace App\Domain\Billing;

use App\Models\PaymentLedger;
use App\Modules\MillsSubscriptions\Enums\LedgerStatus;
use App\Modules\MillsSubscriptions\Exceptions\IllegalTransitionException;

/**
 * The money truth, in one place (CLAUDE.md law #2). Every charge opens a `pending`
 * row HERE before any PayMe call — so a process death mid-charge leaves a
 * reconcilable trace — and the result transitions that exact row to
 * succeeded/failed/retry_scheduled.
 *
 * `hasSucceeded()` is the idempotent short-circuit: if a succeeded row exists for
 * the key, the caller must NOT send a second PayMe charge. Transitions are guarded
 * against the canonical LedgerStatus machine. Single-tenant — keyed on
 * idempotency_key alone (unique).
 */
final class Ledger
{
    /** Has a SUCCEEDED ledger row already been recorded for this key? */
    public static function hasSucceeded(string $idempotencyKey): bool
    {
        return PaymentLedger::query()
            ->where('idempotency_key', $idempotencyKey)
            ->where('status', LedgerStatus::SUCCEEDED->value)
            ->exists();
    }

    /** Find the ledger row for this key (any status), or null. */
    public static function find(string $idempotencyKey): ?PaymentLedger
    {
        return PaymentLedger::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    /**
     * Open (or reuse) a `pending` ledger row for a charge. Idempotent on the
     * unique `idempotency_key`: an existing row is returned as-is (a retry reuses
     * the same row through its lifecycle).
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function open(
        string $context,
        string $idempotencyKey,
        float $amount,
        string $currency = 'ILS',
        array $attributes = [],
    ): PaymentLedger {
        $existing = self::find($idempotencyKey);
        if ($existing !== null) {
            return $existing;
        }

        $row = PaymentLedger::query()->create(array_merge([
            'context' => $context,
            'idempotency_key' => $idempotencyKey,
            'amount' => round($amount, 2),
            'currency' => $currency,
        ], $attributes));

        // `status` is mass-assignment-guarded (state machine). A new row is BORN
        // `pending`; set the initial state via forceFill so the returned instance
        // carries it for the next transition.
        $row->forceFill(['status' => LedgerStatus::PENDING->value])->save();

        return $row;
    }

    /**
     * Guarded ledger transition. Rejects moves outside the canonical machine.
     *
     * @param  array<string, mixed>  $patch  extra columns set on the same write
     */
    public static function transition(PaymentLedger $row, LedgerStatus $to, array $patch = []): PaymentLedger
    {
        // PaymentLedger casts `status` to the enum; tolerate a raw string too.
        $from = $row->status instanceof LedgerStatus
            ? $row->status
            : LedgerStatus::from((string) $row->status);

        if ($from === $to) {
            if ($patch !== []) {
                $row->forceFill($patch)->save();
            }

            return $row;
        }

        $legal = LedgerStatus::allowed()[$from->value] ?? [];
        $isLegal = false;
        foreach ($legal as $candidate) {
            if ($candidate === $to) {
                $isLegal = true;
                break;
            }
        }

        if (! $isLegal) {
            throw new IllegalTransitionException($row, $from, $to);
        }

        $row->forceFill(array_merge($patch, ['status' => $to->value]))->save();

        return $row;
    }
}
