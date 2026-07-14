<?php

namespace App\Models;

use App\Modules\MillsSubscriptions\Concerns\HasGuardedStatus;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `status` is guarded — the ONLY legal way to change it is transitionTo()
 * (HasGuardedStatus). The initial value is set via forceFill/insert.
 */
class Subscription extends Model
{
    use HasGuardedStatus;

    // === CONSTANTS ===
    public const STATUS_COLUMN = 'status';

    protected $guarded = ['id', 'status'];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'payment_state' => PaymentState::class,
            'frequency_months' => 'integer',
            'attempt_count' => 'integer',
            'next_charge_at' => 'datetime',
            'next_charge_amount' => 'decimal:2',
            'next_charge_amount_at' => 'datetime',
            'discount_percent' => 'decimal:2',
            'line_items_override' => 'array',
            'line_items_overridden_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    // --- Guarded state machine wiring ---
    public function statusColumn(): string
    {
        return self::STATUS_COLUMN;
    }

    /** @return array<string, list<BackedEnum>> */
    public function allowedTransitions(): array
    {
        return SubscriptionStatus::allowed();
    }

    public function currentStatus(): BackedEnum
    {
        return $this->status ?? SubscriptionStatus::PENDING;
    }

    public function timelineSubscriptionId(): ?int
    {
        return $this->id;
    }

    public function timelineCustomerId(): ?int
    {
        return $this->customer_id;
    }

    // --- Relationships ---
    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<Dog, $this> */
    public function dogs(): HasMany
    {
        return $this->hasMany(Dog::class);
    }

    /** @return HasMany<PaymentLedger, $this> */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(PaymentLedger::class);
    }

    /**
     * Has this subscription fallen so far behind that billing it would be a surprise?
     *
     * A successful charge advances `next_charge_at` from the OLD due date, not from today —
     * which is right for a subscription running on time, and dangerous for one that is not.
     * A subscription stuck two months in the past gets charged for the first missed cycle,
     * advances into a date that is STILL in the past, and is charged again on the next run
     * five minutes later: two months of billing in ten minutes, for boxes that were never
     * shipped, with no human anywhere in the loop.
     *
     * "More than one whole cycle behind" is the line: add one cycle to the due date, and if
     * that is still in the past, at least one cycle was missed entirely. Those never charge
     * automatically — they wait for a person.
     */
    public function isTooFarBehindToCharge(): bool
    {
        if ($this->next_charge_at === null) {
            return false;
        }

        $months = max(1, (int) $this->frequency_months);

        return $this->next_charge_at->copy()->addMonthsNoOverflow($months)->isPast();
    }

    /**
     * The query mirror of isTooFarBehindToCharge().
     *
     * Written out per frequency rather than as a raw interval expression, so it means the same
     * thing on Postgres and on the SQLite the tests run against — a guard that only holds in
     * production is a guard nobody can prove.
     *
     * @param  Builder<Subscription>  $query
     */
    public function scopeTooFarBehind(Builder $query): void
    {
        $query->whereNotNull('next_charge_at')->where(function (Builder $q) {
            $q->where(function (Builder $q) {
                $q->where('frequency_months', 2)
                    ->where('next_charge_at', '<', now()->subMonthsNoOverflow(2));
            })->orWhere(function (Builder $q) {
                $q->where('frequency_months', '!=', 2)
                    ->where('next_charge_at', '<', now()->subMonthNoOverflow());
            });
        });
    }
}
