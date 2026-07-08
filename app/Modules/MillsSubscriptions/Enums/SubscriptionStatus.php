<?php

namespace App\Modules\MillsSubscriptions\Enums;

/**
 * SubscriptionStatus — the guarded subscription lifecycle (ARCHITECTURE.md §3).
 * Internal vocabulary is richer than v1's; the API edge maps to/from v1's three
 * strings (`active|pending|disable`) so the frozen contract is preserved.
 *
 *   pending   → active, cancelled
 *   active    → paused, past_due, cancelled
 *   paused    → active, cancelled
 *   past_due  → active, cancelled
 *   cancelled → (terminal)
 *
 * Cancellation is ALWAYS immediate (no end-of-period mode anywhere).
 */
enum SubscriptionStatus: string
{
    case PENDING = 'pending';
    case ACTIVE = 'active';
    case PAUSED = 'paused';
    case PAST_DUE = 'past_due';
    case CANCELLED = 'cancelled';

    // === LEGACY EDGE VOCABULARY (v1 frozen contract) ===
    public const LEGACY_ACTIVE = 'active';

    public const LEGACY_PENDING = 'pending';

    public const LEGACY_DISABLE = 'disable';

    /** @return array<string, list<self>> */
    public static function allowed(): array
    {
        return [
            self::PENDING->value => [self::ACTIVE, self::CANCELLED],
            self::ACTIVE->value => [self::PAUSED, self::PAST_DUE, self::CANCELLED],
            self::PAUSED->value => [self::ACTIVE, self::CANCELLED],
            self::PAST_DUE->value => [self::ACTIVE, self::CANCELLED],
            self::CANCELLED->value => [],
        ];
    }

    public function isTerminal(): bool
    {
        return $this === self::CANCELLED;
    }

    public function isBillable(): bool
    {
        return $this === self::ACTIVE;
    }

    /**
     * Serialize to v1's API vocabulary (active|pending|disable). paused/past_due
     * are internal-only; the theme only understands the three legacy strings.
     */
    public function toLegacy(): string
    {
        return match ($this) {
            self::ACTIVE, self::PAST_DUE => self::LEGACY_ACTIVE,
            self::PENDING, self::PAUSED => self::LEGACY_PENDING,
            self::CANCELLED => self::LEGACY_DISABLE,
        };
    }

    /** Translate an inbound v1 status string to the internal enum. */
    public static function fromLegacy(string $legacy): self
    {
        return match (strtolower(trim($legacy))) {
            self::LEGACY_ACTIVE => self::ACTIVE,
            self::LEGACY_DISABLE => self::CANCELLED,
            default => self::PENDING,
        };
    }
}
