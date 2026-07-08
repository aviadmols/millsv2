<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Single-row admin-managed mail configuration (D12). `smtp_password` is encrypted.
 * Use MailSetting::current() to fetch (lazily creates the row).
 */
class MailSetting extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['smtp_password'];

    protected function casts(): array
    {
        return [
            'use_custom_smtp' => 'boolean',
            'smtp_password' => 'encrypted',
            'smtp_port' => 'integer',
            'templates' => 'array',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate([], []);
    }
}
