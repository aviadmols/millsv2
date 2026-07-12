<?php

namespace App\Models;

use App\Support\PaymentContextMasker;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Append-only structured application log — the single, simple place every part of
 * the system records what it did. Write via the static helpers:
 *
 *   SystemLog::info('billing', 'charged subscription', ['amount' => 120], ['subscription_id' => 5]);
 *   SystemLog::error('shopify', 'order create failed', ['errors' => $e]);
 *
 * Sensitive keys (token/card/buyer_key/…) are masked before storage (CLAUDE.md
 * law #7). Retention: `logs:prune` deletes rows older than
 * config('mills.logging.retention_days') (default 60), scheduled daily.
 */
class SystemLog extends Model
{
    public const UPDATED_AT = null;

    public const LEVEL_INFO = 'info';

    public const LEVEL_WARNING = 'warning';

    public const LEVEL_ERROR = 'error';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
            'status_code' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $meta  optional: subscription_id, customer_id, method, path, status_code, duration_ms, ip
     */
    public static function write(string $level, string $category, string $message, array $context = [], array $meta = []): void
    {
        try {
            static::query()->create([
                'level' => $level,
                'category' => $category,
                'message' => mb_substr($message, 0, 500),
                'context' => $context === [] ? null : PaymentContextMasker::mask($context),
                'subscription_id' => $meta['subscription_id'] ?? null,
                'customer_id' => $meta['customer_id'] ?? null,
                'method' => $meta['method'] ?? null,
                'path' => isset($meta['path']) ? mb_substr((string) $meta['path'], 0, 255) : null,
                'status_code' => $meta['status_code'] ?? null,
                'duration_ms' => $meta['duration_ms'] ?? null,
                'ip' => $meta['ip'] ?? null,
            ]);
        } catch (Throwable) {
            // Logging must never break the operation it is recording.
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $meta
     */
    public static function info(string $category, string $message, array $context = [], array $meta = []): void
    {
        static::write(self::LEVEL_INFO, $category, $message, $context, $meta);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $meta
     */
    public static function warning(string $category, string $message, array $context = [], array $meta = []): void
    {
        static::write(self::LEVEL_WARNING, $category, $message, $context, $meta);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $meta
     */
    public static function error(string $category, string $message, array $context = [], array $meta = []): void
    {
        static::write(self::LEVEL_ERROR, $category, $message, $context, $meta);
    }
}
