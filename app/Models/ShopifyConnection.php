<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The single Shopify app connection (ARCHITECTURE.md §1b). `access_token` is the
 * encrypted OAuth offline token; kept hidden and never logged.
 */
class ShopifyConnection extends Model
{
    protected $table = 'shopify_connection';

    protected $guarded = ['id'];

    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'scopes' => 'array',
            'installed_at' => 'datetime',
            'uninstalled_at' => 'datetime',
        ];
    }

    public static function current(): ?self
    {
        return static::query()->first();
    }

    public function isConnected(): bool
    {
        return $this->access_token !== null && $this->uninstalled_at === null;
    }
}
