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
            'order_resolved_at' => 'datetime',
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
     * is given a grace period before it is called missing. One an admin has marked as dealt
     * with is not missing any more — it is handled, see isOrderResolvedByHand().
     */
    public function isMissingOrder(): bool
    {
        $status = $this->status instanceof LedgerStatus ? $this->status : LedgerStatus::tryFrom((string) $this->status);

        return $status === LedgerStatus::SUCCEEDED
            && in_array($this->context, IdempotencyKey::billingContexts(), true)
            && blank($this->shopify_order_id)
            && $this->order_resolved_at === null
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
            ->whereNull('order_resolved_at')
            ->where(fn (Builder $q) => $q->whereNull('executed_at')
                ->orWhere('executed_at', '<', now()->subMinutes(self::ORDER_GRACE_MINUTES)));
    }

    /** Paid, no order from the system — and an admin has marked it as dealt with. */
    public function isOrderResolvedByHand(): bool
    {
        return blank($this->shopify_order_id) && $this->order_resolved_at !== null;
    }

    /**
     * Close the "paid, no order" alert for this charge.
     *
     * Only the order bookkeeping is touched — never status or amount; the money that moved
     * stays exactly as recorded. When the admin created the order by hand and says which one,
     * it is linked, so the billing history points at the real order like any other charge.
     */
    public function resolveMissingOrder(string $actor, ?string $shopifyOrderId = null, string $note = ''): void
    {
        $this->forceFill(array_filter([
            'order_resolved_at' => now(),
            'order_resolved_by' => $actor,
            'order_resolved_note' => $note !== '' ? $note : null,
            'shopify_order_id' => $shopifyOrderId ?: null,
        ], fn ($value) => $value !== null))->save();
    }

    /**
     * The numeric Shopify order id in whatever the admin pasted: the order page's address
     * (…/orders/19030456926512) or the id itself. An order NAME such as "#74500" is not an
     * id — Shopify cannot be addressed by it — so it is refused rather than stored.
     */
    public static function shopifyOrderIdFrom(?string $input): ?string
    {
        $input = trim((string) $input);

        if ($input === '') {
            return null;
        }

        if (preg_match('~/orders/(\d{6,})~', $input, $m) || preg_match('~^(?:gid://shopify/Order/)?(\d{10,})$~', $input, $m)) {
            return $m[1];
        }

        return null;
    }

    /** Long enough for an order to be created after its charge; short enough to matter. */
    public const ORDER_GRACE_MINUTES = 10;
}
