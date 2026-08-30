<?php

namespace App\Support\Ui;

use App\Models\ActivityEvent;
use App\Models\User;
use App\Modules\MillsSubscriptions\Support\Timeline;

/**
 * Turns an ActivityEvent into the three things a person actually reads: a title, a
 * one-line summary, and who did it.
 *
 * One presenter for BOTH surfaces — the Activity table and the timeline on the
 * subscription screen. They used to be the same sentences written twice, which is how
 * a feed ends up saying "charge_succeeded" in one place and "חיוב בוצע" in the other.
 *
 * SAFETY: summarize() reads a WHITELIST of detail keys. A buyer_key, a token
 * fingerprint or a raw PayMe payload that finds its way into `details` is dropped
 * here, not in the Blade — the view can only print what this returns.
 */
final class EventPresenter
{
    /** kind => [tone, translation key]. The tone drives the timeline dot colour. */
    public const KINDS = [
        Timeline::KIND_SUBSCRIPTION_CREATED => ['info', 'activity.kind_subscription_created'],
        Timeline::KIND_QUIZ_LINKED => ['info', 'activity.kind_quiz_linked'],
        Timeline::KIND_CHARGE_SUCCEEDED => ['success', 'activity.kind_charge_succeeded'],
        Timeline::KIND_CHARGE_FAILED => ['failure', 'activity.kind_charge_failed'],
        Timeline::KIND_ORDER_CREATED => ['success', 'activity.kind_order_created'],
        // WARNING, not success: a card update is the event support is looking for when a
        // customer says "I paid and nothing happened". It has to stand out in the scan.
        Timeline::KIND_CARD_UPDATED => ['warning', 'activity.kind_card_updated'],
        Timeline::KIND_STATUS_CHANGED => ['info', 'activity.kind_status_changed'],
        Timeline::KIND_ADDRESS_UPDATED => ['info', 'activity.kind_address_updated'],
        Timeline::KIND_DOG_UPDATED => ['info', 'activity.kind_dog_updated'],
        Timeline::KIND_PLAN_UPDATED => ['info', 'activity.kind_plan_updated'],
        Timeline::KIND_ADMIN_NOTE => ['gray', 'activity.kind_admin_note'],
        Timeline::KIND_NOTE => ['gray', 'activity.kind_note'],
    ];

    public const FALLBACK = ['gray', 'activity.kind_generic'];

    /**
     * Detail keys that may be shown. Everything else is dropped — `details` carries
     * whatever the caller passed, and this is the only gate before it reaches a screen.
     */
    public const SAFE_DETAIL_KEYS = [
        'amount', 'failure_code', 'status', 'reason', 'from', 'to', 'model',
        'subscriptions_unblocked', 'recovered_by_reconciliation', 'dog_name', 'weight',
        'source', 'dogs', 'shopify_order_id', 'order', 'payment_state', 'fields',
        'note', 'actor', 'mode',
        'frequency_from', 'frequency_to', 'charge_date_from', 'charge_date_to',
    ];

    public static function tone(ActivityEvent $event): string
    {
        return (self::KINDS[$event->kind] ?? self::FALLBACK)[0];
    }

    public static function label(ActivityEvent $event): string
    {
        return self::labelFor((string) $event->kind);
    }

    /** A kind with no translation reads as itself, never as a missing-key token. */
    public static function labelFor(string $kind): string
    {
        $key = (self::KINDS[$kind] ?? self::FALLBACK)[1];

        if ($kind !== '' && ! isset(self::KINDS[$kind])) {
            $key = 'activity.kind_'.$kind;
        }

        $translated = __($key);

        return $translated === $key ? str_replace('_', ' ', $kind) : $translated;
    }

    /**
     * A merchant's own words, on a note event only. Rendered as prose rather than as
     * part of the summary line: it is the content, and it is written in whatever
     * language the person typed.
     */
    public static function note(ActivityEvent $event): ?string
    {
        if (! in_array($event->kind, [Timeline::KIND_ADMIN_NOTE, Timeline::KIND_NOTE], true)) {
            return null;
        }

        $note = trim((string) (((array) ($event->details ?? []))['note'] ?? ''));

        return $note !== '' ? $note : null;
    }

    /** "admin:7" reads as the person's name; system/customer/webhook read as themselves. */
    public static function actorLabel(ActivityEvent|string $event): string
    {
        $actor = is_string($event) ? $event : (string) ($event->actor ?? Timeline::ACTOR_SYSTEM);

        if (str_starts_with($actor, 'admin:')) {
            return self::adminName((int) substr($actor, 6));
        }

        $key = 'activity.actor_'.$actor;

        return __($key) === $key ? $actor : __($key);
    }

    /**
     * The one-line story of the event, built per kind from whitelisted details.
     *
     * Anything unrecognised degrades to a readable "key: value" list rather than to raw
     * JSON — a feed that prints `{"ledger_id":7}` is a log nobody reads twice.
     */
    public static function summarize(ActivityEvent $event): string
    {
        $d = array_intersect_key(
            (array) ($event->details ?? []),
            array_flip(self::SAFE_DETAIL_KEYS),
        );

        return match ($event->kind) {
            // The note IS the content; note() renders it as prose, so the summary stays empty.
            Timeline::KIND_ADMIN_NOTE => '',
            Timeline::KIND_CHARGE_SUCCEEDED => __('activity.sum_charged', [
                'amount' => '₪'.number_format((float) ($d['amount'] ?? 0), 2),
            ]),
            Timeline::KIND_CHARGE_FAILED => __('activity.sum_charge_failed', [
                'reason' => (string) ($d['failure_code'] ?? $d['status'] ?? '—'),
            ]),
            Timeline::KIND_CARD_UPDATED => __('activity.sum_card_updated', [
                'count' => (int) ($d['subscriptions_unblocked'] ?? 0),
            ]).(($d['recovered_by_reconciliation'] ?? false) ? ' · '.__('activity.sum_recovered') : ''),
            Timeline::KIND_QUIZ_LINKED => __('activity.sum_quiz', [
                'dog' => (string) ($d['dog_name'] ?? '—'),
                'weight' => (string) ($d['weight'] ?? '?'),
            ]),
            Timeline::KIND_SUBSCRIPTION_CREATED => __('activity.sum_subscription_created', [
                'source' => (string) ($d['source'] ?? '—'),
                'dogs' => (int) ($d['dogs'] ?? 0),
            ]),
            Timeline::KIND_ORDER_CREATED => __('activity.sum_order', [
                'order' => (string) ($d['shopify_order_id'] ?? $d['order'] ?? '—'),
            ]),
            Timeline::KIND_STATUS_CHANGED => self::statusSummary($d),
            Timeline::KIND_ADDRESS_UPDATED => __('activity.sum_address'),
            Timeline::KIND_PLAN_UPDATED => self::planSummary($d),
            default => self::readableDetails($d),
        };
    }

    /**
     * What the customer actually changed about their plan, in words, with both values.
     *
     * Both halves can be present in one row — changing the frequency and the date is one
     * save from the customer's side, and splitting it into two rows would misrepresent it
     * as two visits.
     *
     * @param  array<string, mixed>  $d
     */
    private static function planSummary(array $d): string
    {
        $parts = [];

        if (isset($d['frequency_to'])) {
            $parts[] = __('activity.sum_frequency', [
                'from' => self::frequencyLabel($d['frequency_from'] ?? null),
                'to' => self::frequencyLabel($d['frequency_to']),
            ]);
        }

        if (isset($d['charge_date_to'])) {
            $parts[] = __('activity.sum_charge_date', [
                'from' => (string) ($d['charge_date_from'] ?? '—'),
                'to' => (string) $d['charge_date_to'],
            ]);
        }

        return $parts === [] ? self::readableDetails($d) : implode(' · ', $parts);
    }

    private static function frequencyLabel(mixed $months): string
    {
        if ($months === null) {
            return '—';
        }

        return (int) $months === 2 ? __('subscriptions.every_2_months') : __('subscriptions.monthly');
    }

    /**
     * A status change as written by transitionTo(): model, from, to, plus whatever context
     * the caller passed (a pause reason, for instance).
     *
     * @param  array<string, mixed>  $d
     */
    private static function statusSummary(array $d): string
    {
        if (isset($d['payment_state'])) {
            return __('activity.sum_payment_state', ['state' => (string) $d['payment_state']]);
        }

        $from = (string) ($d['from'] ?? '');
        $to = (string) ($d['to'] ?? '');

        if ($to === '') {
            return self::readableDetails($d);
        }

        $line = __('activity.sum_status', [
            'model' => self::modelLabel((string) ($d['model'] ?? '')),
            'from' => self::statusLabel($from),
            'to' => self::statusLabel($to),
        ]);

        $reason = trim((string) ($d['reason'] ?? ''));

        return $reason === '' ? $line : $line.' — '.$reason;
    }

    private static function statusLabel(string $status): string
    {
        $key = 'subscriptions.status_'.$status;

        return $status === '' ? '—' : (__($key) === $key ? $status : __($key));
    }

    private static function modelLabel(string $model): string
    {
        $key = 'activity.model_'.strtolower($model);

        return __($key) === $key ? $model : __($key);
    }

    /** @param array<string, mixed> $d */
    private static function readableDetails(array $d): string
    {
        if ($d === []) {
            return '—';
        }

        $parts = [];
        foreach ($d as $key => $value) {
            if (is_object($value)) {
                continue;   // nested payloads belong in the detail view, not a one-liner
            }

            /*
             * A flat list of scalars reads perfectly well on one line, and dropping it did
             * real damage: the customer self-service events recorded `['fields' => [...]]`
             * and nothing else, so every one of them rendered as a completely blank row —
             * a history that proves something happened and refuses to say what.
             */
            if (is_array($value)) {
                $flat = array_filter($value, fn ($v) => is_scalar($v));

                if ($flat === [] || count($flat) !== count($value)) {
                    continue;
                }

                $value = implode(', ', array_map(fn ($v) => str_replace('_', ' ', (string) $v), $flat));
            }

            $parts[] = str_replace('_', ' ', (string) $key).': '
                .(is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value);
        }

        return $parts === [] ? '—' : implode(' · ', $parts);
    }

    /** @var array<int, string> request-static: one lookup per admin, not per row */
    private static array $adminNameCache = [];

    private static function adminName(int $id): string
    {
        if ($id <= 0) {
            return __('activity.actor_admin', ['id' => $id]);
        }

        if (! array_key_exists($id, self::$adminNameCache)) {
            $user = User::query()->find($id);
            $name = $user !== null ? trim((string) ($user->name ?: $user->email)) : '';
            self::$adminNameCache[$id] = $name !== '' ? $name : __('activity.actor_admin', ['id' => $id]);
        }

        return self::$adminNameCache[$id];
    }
}
