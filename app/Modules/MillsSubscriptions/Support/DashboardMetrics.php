<?php

namespace App\Modules\MillsSubscriptions\Support;

use App\Models\ActivityEvent;
use App\Models\PaymentLedger;
use App\Models\Subscription;
use App\Modules\MillsSubscriptions\Enums\LedgerStatus;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Every number the dashboard shows, in one testable place.
 *
 * Revenue is read from the payment ledger — the immutable money truth — and NEVER from
 * the subscriptions table, so what the dashboard claims was collected is what was
 * actually collected.
 *
 * The upcoming figures are the mirror image: they come from the subscriptions that are
 * genuinely billable (active, on PayMe, with a known amount), so "next 7 days: ₪X" is a
 * promise the biller can actually keep rather than an optimistic sum over rows that will
 * be skipped.
 */
final class DashboardMetrics
{
    /** Money COLLECTED in a window (the ledger, not the plan). */
    public static function revenue(Carbon|CarbonImmutable $from, Carbon|CarbonImmutable $to): float
    {
        return (float) PaymentLedger::query()
            ->where('status', LedgerStatus::SUCCEEDED->value)
            ->whereBetween('executed_at', [$from, $to])
            ->sum('amount');
    }

    /** How many charges went through in a window. */
    public static function chargeCount(Carbon|CarbonImmutable $from, Carbon|CarbonImmutable $to): int
    {
        return PaymentLedger::query()
            ->where('status', LedgerStatus::SUCCEEDED->value)
            ->whereBetween('executed_at', [$from, $to])
            ->count();
    }

    /** Charges that FAILED in a window — the number that actually needs attention. */
    public static function failedCount(Carbon|CarbonImmutable $from, Carbon|CarbonImmutable $to): int
    {
        return PaymentLedger::query()
            ->where('status', LedgerStatus::FAILED->value)
            ->whereBetween('executed_at', [$from, $to])
            ->count();
    }

    /** Daily revenue, oldest first — the sparkline under the revenue card. */
    public static function revenueSeries(int $days = 14): array
    {
        $series = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $day = now()->subDays($i);
            $series[] = self::revenue($day->copy()->startOfDay(), $day->copy()->endOfDay());
        }

        return $series;
    }

    public static function activeSubscriptions(): int
    {
        return Subscription::query()
            ->where('status', SubscriptionStatus::ACTIVE->value)
            ->count();
    }

    public static function pausedSubscriptions(): int
    {
        return Subscription::query()
            ->where('status', SubscriptionStatus::PAUSED->value)
            ->count();
    }

    /** Subscriptions still blocked behind a card update — they will never be charged. */
    public static function needCardUpdate(): int
    {
        return Subscription::query()
            ->where('status', SubscriptionStatus::ACTIVE->value)
            ->where('payment_state', PaymentState::NEEDS_CARD_UPDATE->value)
            ->count();
    }

    public static function newSubscriptions(Carbon|CarbonImmutable $from, Carbon|CarbonImmutable $to): int
    {
        return Subscription::query()->whereBetween('created_at', [$from, $to])->count();
    }

    /**
     * Churn, read from the audit trail rather than a status column — a subscription that
     * is cancelled today and one cancelled a year ago look identical in `status`, so the
     * status alone cannot answer "how many churned this month".
     */
    public static function churned(Carbon|CarbonImmutable $from, Carbon|CarbonImmutable $to): int
    {
        return ActivityEvent::query()
            ->where('kind', Timeline::KIND_STATUS_CHANGED)
            ->whereBetween('created_at', [$from, $to])
            ->where('details->to', SubscriptionStatus::CANCELLED->value)
            ->count();
    }

    /**
     * The upcoming orders — only the ones that will REALLY be charged.
     *
     * Deliberately excludes: paused/cancelled subscriptions, anything still awaiting a
     * card, and anything whose amount we do not know. A dashboard that counts money it
     * cannot collect is worse than one that shows a smaller, true number.
     *
     * @return array{count: int, total: float, unknown_amount: int}
     */
    public static function upcoming(int $withinDays): array
    {
        $billable = self::billableQuery()->whereBetween('next_charge_at', [
            now()->startOfDay(),
            now()->addDays($withinDays)->endOfDay(),
        ]);

        return [
            'count' => (clone $billable)->count(),
            'total' => (float) (clone $billable)->sum('next_charge_amount'),
            // Billable, due, but we do not know what to charge — these will abort.
            'unknown_amount' => (clone $billable)
                ->where(fn ($q) => $q->whereNull('next_charge_amount')->orWhere('next_charge_amount', '<=', 0))
                ->count(),
        ];
    }

    /**
     * Charges that are already due and have not gone out — with billing down, this is the
     * backlog, and it is the single most important number on the page.
     *
     * @return array{count: int, total: float}
     */
    public static function overdue(): array
    {
        $overdue = self::billableQuery()->where('next_charge_at', '<', now()->startOfDay());

        return [
            'count' => (clone $overdue)->count(),
            'total' => (float) (clone $overdue)->sum('next_charge_amount'),
        ];
    }

    /** The subscriptions behind the upcoming figures, soonest first. */
    public static function upcomingQuery(int $withinDays = 30)
    {
        return self::billableQuery()
            ->with(['customer', 'dogs'])
            ->whereBetween('next_charge_at', [
                now()->subYear(),                       // include the overdue backlog
                now()->addDays($withinDays)->endOfDay(),
            ])
            ->orderBy('next_charge_at');
    }

    /** Percentage change, guarding the divide-by-zero that makes dashboards lie. */
    public static function trend(float $current, float $previous): ?float
    {
        if ($previous <= 0.0) {
            // Growth from nothing is not "+100%" — it is undefined, and saying so is honest.
            return $current > 0 ? null : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /** Active + on PayMe = the only subscriptions the dispatcher will ever charge. */
    private static function billableQuery()
    {
        return Subscription::query()
            ->where('status', SubscriptionStatus::ACTIVE->value)
            ->where('payment_state', PaymentState::PAYME->value)
            ->whereNotNull('next_charge_at');
    }
}
