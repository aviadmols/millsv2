<?php

namespace App\Modules\MillsSubscriptions\Support;

use App\Models\ActivityEvent;

/**
 * The append-only audit feed (activity_events). Every status transition, charge,
 * card update, and admin/customer action records one row here — the human-facing
 * history for a subscription/customer. Rows are never updated or deleted.
 */
final class Timeline
{
    // === CONSTANTS: event kinds ===
    public const KIND_STATUS_CHANGED = 'status_changed';

    public const KIND_CHARGE_SUCCEEDED = 'charge_succeeded';

    public const KIND_CHARGE_FAILED = 'charge_failed';

    public const KIND_CARD_UPDATED = 'card_updated';

    public const KIND_ADDRESS_UPDATED = 'address_updated';

    public const KIND_ORDER_CREATED = 'order_created';

    public const KIND_SUBSCRIPTION_CREATED = 'subscription_created';

    public const KIND_NOTE = 'note';

    // === CONSTANTS: actors ===
    public const ACTOR_SYSTEM = 'system';

    public const ACTOR_CUSTOMER = 'customer';

    public const ACTOR_WEBHOOK = 'webhook';

    /**
     * @param  array<string, mixed>  $details
     */
    public static function record(
        string $kind,
        array $details = [],
        ?int $subscriptionId = null,
        ?int $customerId = null,
        string $actor = self::ACTOR_SYSTEM,
    ): void {
        ActivityEvent::query()->create([
            'subscription_id' => $subscriptionId,
            'customer_id' => $customerId,
            'actor' => $actor,
            'kind' => $kind,
            'details' => $details,
        ]);
    }

    /** Convenience for admin-initiated actions (actor = "admin:{id}"). */
    public static function admin(int $adminId): string
    {
        return 'admin:'.$adminId;
    }
}
