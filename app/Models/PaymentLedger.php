<?php

namespace App\Models;

use App\Domain\Billing\IdempotencyKey;
use App\Modules\MillsSubscriptions\Enums\LedgerStatus;
use Illuminate\Database\Eloquent\Builder;
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
            'refunded_amount' => 'decimal:2',
            'raw_response_masked' => 'array',
            'meta' => 'array',
            'executed_at' => 'datetime',
            'refunded_at' => 'datetime',
            'order_attempted_at' => 'datetime',
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

    /**
     * Money we took for a subscription, with no Shopify order to ship it.
     *
     * The single definition both screens use, so the billing history and the home screen
     * can never disagree about who is waiting for a box that is not coming. Only a real
     * subscription charge that succeeded counts — a card verification or a v1 import never
     * had an order to create — and a charge minutes old is still creating its order, so it
     * is given a grace period before it is called missing.
     */
    public function isMissingOrder(): bool
    {
        $status = $this->status instanceof LedgerStatus ? $this->status : LedgerStatus::tryFrom((string) $this->status);

        return $status === LedgerStatus::SUCCEEDED
            && in_array($this->context, IdempotencyKey::billingContexts(), true)
            && blank($this->shopify_order_id)
            && ($this->executed_at === null || $this->executed_at->lt(now()->subMinutes(self::ORDER_GRACE_MINUTES)));
    }

    /**
     * The query mirror of isMissingOrder().
     *
     * @param  Builder<PaymentLedger>  $query
     * @return Builder<PaymentLedger>
     */
    public function scopeMissingOrder(Builder $query): Builder
    {
        return $query
            ->where('status', LedgerStatus::SUCCEEDED->value)
            ->whereIn('context', IdempotencyKey::billingContexts())
            ->where(fn (Builder $q) => $q->whereNull('shopify_order_id')->orWhere('shopify_order_id', ''))
            ->where(fn (Builder $q) => $q->whereNull('executed_at')
                ->orWhere('executed_at', '<', now()->subMinutes(self::ORDER_GRACE_MINUTES)));
    }

    /** Long enough for an order to be created after its charge; short enough to matter. */
    public const ORDER_GRACE_MINUTES = 10;
}
