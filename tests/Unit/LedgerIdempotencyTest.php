<?php

namespace Tests\Unit;

use App\Domain\Billing\IdempotencyKey;
use App\Domain\Billing\Ledger;
use App\Models\PaymentLedger;
use App\Modules\MillsSubscriptions\Enums\LedgerStatus;
use App\Modules\MillsSubscriptions\Exceptions\IllegalTransitionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves CLAUDE.md law #2: a ledger row is written before the charge, the
 * idempotency key blocks a second charge, and ledger transitions are guarded.
 */
class LedgerIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_is_idempotent_on_the_key(): void
    {
        $key = IdempotencyKey::recurring(subscriptionId: 1, cycleDate: '2026-06-24');

        $a = Ledger::open(IdempotencyKey::CONTEXT_RECURRING, $key, 153.90);
        $b = Ledger::open(IdempotencyKey::CONTEXT_RECURRING, $key, 153.90);

        $this->assertSame($a->id, $b->id, 'A second open() for the same key reuses the row');
        $this->assertSame(1, PaymentLedger::query()->count());
        $this->assertSame(LedgerStatus::PENDING, $a->status);
    }

    public function test_has_succeeded_short_circuits_a_second_charge(): void
    {
        $key = IdempotencyKey::recurring(subscriptionId: 7, cycleDate: '2026-06-24');
        $row = Ledger::open(IdempotencyKey::CONTEXT_RECURRING, $key, 100.0);

        $this->assertFalse(Ledger::hasSucceeded($key));

        Ledger::transition($row, LedgerStatus::SUCCEEDED, ['payme_transaction_id' => 'tx_1']);

        $this->assertTrue(Ledger::hasSucceeded($key), 'A succeeded row must block a re-charge');
    }

    public function test_illegal_ledger_transition_throws(): void
    {
        $key = IdempotencyKey::recurring(subscriptionId: 9, cycleDate: '2026-06-24');
        $row = Ledger::open(IdempotencyKey::CONTEXT_RECURRING, $key, 100.0);
        Ledger::transition($row, LedgerStatus::SUCCEEDED);

        // succeeded → failed is not a legal move (only succeeded → refunded).
        $this->expectException(IllegalTransitionException::class);
        Ledger::transition($row, LedgerStatus::FAILED);
    }

    public function test_deterministic_keys_are_stable(): void
    {
        $this->assertSame('recurring:5:2026-06-24', IdempotencyKey::recurring(5, '2026-06-24'));
        $this->assertSame('retry:42:2', IdempotencyKey::retry(42, 2));
        $this->assertSame('manual:5:3:2026-06-24', IdempotencyKey::manual(5, 3, '2026-06-24'));
    }
}
