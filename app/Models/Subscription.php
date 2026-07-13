<?php

namespace App\Models;

use App\Modules\MillsSubscriptions\Concerns\HasGuardedStatus;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use BackedEnum;
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
}
