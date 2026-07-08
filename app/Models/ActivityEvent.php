<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only Timeline row. No `updated_at` — set only on insert.
 */
class ActivityEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
