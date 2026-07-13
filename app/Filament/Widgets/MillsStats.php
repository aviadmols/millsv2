<?php

namespace App\Filament\Widgets;

use App\Modules\MillsSubscriptions\Support\DashboardMetrics;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The business at a glance: what came in, and what is on the books.
 *
 * Revenue is read from the payment ledger — the immutable money truth — so the figure on
 * screen is money that actually arrived, never money the plan merely hoped for.
 */
class MillsStats extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $period = 30;

        $from = now()->subDays($period);
        $to = now();
        $prevFrom = now()->subDays($period * 2);
        $prevTo = $from;

        $revenue = DashboardMetrics::revenue($from, $to);
        $revenueTrend = DashboardMetrics::trend($revenue, DashboardMetrics::revenue($prevFrom, $prevTo));

        $newSubs = DashboardMetrics::newSubscriptions($from, $to);
        $newTrend = DashboardMetrics::trend($newSubs, DashboardMetrics::newSubscriptions($prevFrom, $prevTo));

        $churned = DashboardMetrics::churned($from, $to);
        $churnTrend = DashboardMetrics::trend($churned, DashboardMetrics::churned($prevFrom, $prevTo));

        $failed = DashboardMetrics::failedCount($from, $to);

        return [
            Stat::make(__('dashboard.processed_revenue'), '₪'.number_format($revenue, 2))
                ->description($this->trendText(
                    $revenueTrend,
                    __('dashboard.charges_count', ['count' => DashboardMetrics::chargeCount($from, $to)]),
                ))
                ->descriptionIcon($this->trendIcon($revenueTrend))
                ->color($this->trendColor($revenueTrend))
                ->chart(DashboardMetrics::revenueSeries(14)),

            Stat::make(__('dashboard.active_subscribers'), DashboardMetrics::activeSubscriptions())
                ->description(__('dashboard.paused_count', ['count' => DashboardMetrics::pausedSubscriptions()]))
                ->descriptionIcon('heroicon-m-users')
                ->color('primary'),

            Stat::make(__('dashboard.new_subscribers'), $newSubs)
                ->description($this->trendText($newTrend, __('dashboard.vs_previous')))
                ->descriptionIcon($this->trendIcon($newTrend))
                ->color($this->trendColor($newTrend)),

            // Churn and failed charges are the two numbers that should look ugly when they
            // are ugly — a dashboard that flatters you is worse than none.
            Stat::make(__('dashboard.churned_subscribers'), $churned)
                ->description($failed > 0
                    ? __('dashboard.failed_charges', ['count' => $failed])
                    : $this->trendText($churnTrend, __('dashboard.vs_previous')))
                ->descriptionIcon($failed > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-arrow-trending-down')
                ->color($churned > 0 || $failed > 0 ? 'danger' : 'success'),
        ];
    }

    private function trendText(?float $trend, string $suffix): string
    {
        // Growth from zero is undefined, not "+100%". Say the honest thing.
        if ($trend === null) {
            return $suffix;
        }

        $sign = $trend > 0 ? '+' : '';

        return "{$sign}{$trend}%  ·  {$suffix}";
    }

    private function trendIcon(?float $trend): string
    {
        return match (true) {
            $trend === null, $trend === 0.0 => 'heroicon-m-minus-small',
            $trend > 0 => 'heroicon-m-arrow-trending-up',
            default => 'heroicon-m-arrow-trending-down',
        };
    }

    private function trendColor(?float $trend): string
    {
        return match (true) {
            $trend === null, $trend === 0.0 => 'gray',
            $trend > 0 => 'success',
            default => 'danger',
        };
    }
}
