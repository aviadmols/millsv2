<?php

namespace App\Models;

use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'address_pushed_at' => 'datetime',
        ];
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** @return HasMany<Dog, $this> */
    public function dogs(): HasMany
    {
        return $this->hasMany(Dog::class);
    }

    /** @return HasMany<PaymentMethod, $this> */
    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class);
    }

    public function activePaymentMethod(): ?PaymentMethod
    {
        return $this->paymentMethods()->where('is_active', true)->latest('captured_at')->first();
    }

    /**
     * Keep the match key in step with the display value automatically — a customer
     * whose phone is updated anywhere in the app must stay findable by SMS login.
     */
    public function setPhoneAttribute(?string $value): void
    {
        $this->attributes['phone'] = $value;
        $this->attributes['phone_normalized'] = PhoneNumber::normalise($value);
    }

    /** Find a customer by any spelling of their phone number. */
    public static function findByPhone(?string $phone): ?self
    {
        $key = PhoneNumber::normalise($phone);

        return $key === null ? null : static::query()->where('phone_normalized', $key)->first();
    }

    public function fullName(): string
    {
        return trim(($this->first_name ?? '').' '.($this->last_name ?? '')) ?: ($this->email ?? '—');
    }
}
