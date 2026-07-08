<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Dog extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'selected_variants' => 'array',
            'addons_products' => 'array',
            'double_food' => 'boolean',
            'birth_date' => 'date',
            'age' => 'float',
            'weight' => 'float',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
