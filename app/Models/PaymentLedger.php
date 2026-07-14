<?php

namespace App\Models;

use App\Modules\MillsSubscriptions\Enums\LedgerStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable money truth (CLAUDE.md law #2). `status` is guarded — only the
 * App\Domain\Billing\Ledger service transitions it, against the LedgerStatus
 * machine. Rows are effectively append-only in spirit (never deleted).
 */
class PaymentLedger extends Model
{
    protected $table = 'payment_ledger';

    protected $guarded = ['id', 'status'];

    protected function casts(): array
    {
        return [
            'status' => LedgerStatus::class,
            'amount' => 'decimal:2',
            'raw_response_masked' => 'array',
            'meta' => 'array',
            'executed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<PaymentMethod, $this> */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}
