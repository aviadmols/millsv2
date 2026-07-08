<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved PayMe buyer_key (CLAUDE.md law #7). `buyer_key` is encrypted at rest and
 * NEVER logged or rendered — only its masked card summary is shown.
 */
class PaymentMethod extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['buyer_key'];

    protected function casts(): array
    {
        return [
            'buyer_key' => 'encrypted',
            'is_active' => 'boolean',
            'captured_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
