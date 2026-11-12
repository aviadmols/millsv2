<?php

namespace App\Observers;

use App\Models\Subscription;
use App\Modules\MillsSubscriptions\Support\Timeline;
use BackedEnum;

/**
 * Puts every move of the billing gate on the timeline.
 *
 * `payment_state` is written from half a dozen places — a card update, the charge
 * pipeline failing closed, an import, the admin edit form — and none of them recorded it.
 * When a saved card was followed two minutes later by "card required" there was no way to
 * tell which of them had done it (subscription 62, 2026-10-28). Hooking the model rather
 * than each writer means a new code path cannot forget.
 */
class SubscriptionObserver
{
    public function updated(Subscription $subscription): void
    {
        if (! $subscription->wasChanged('payment_state')) {
            return;
        }

        $from = self::value($subscription->getRawOriginal('payment_state'));
        $to = self::value($subscription->payment_state);

        if ($from === $to) {
            return;
        }

        // A signed-in admin is a person with a name; anything else (a queue job, the
        // storefront, a webhook) is the system acting, and the neighbouring event says why.
        $actor = auth()->check() ? 'admin:'.auth()->id() : Timeline::ACTOR_SYSTEM;

        Timeline::record(
            Timeline::KIND_PAYMENT_STATE_CHANGED,
            ['from' => $from, 'to' => $to],
            $subscription->id,
            $subscription->customer_id,
            $actor,
        );
    }

    private static function value(mixed $state): ?string
    {
        if ($state instanceof BackedEnum) {
            return (string) $state->value;
        }

        return $state === null ? null : (string) $state;
    }
}
