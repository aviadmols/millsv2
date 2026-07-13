<?php

namespace App\Filament\Widgets;

use App\Modules\MillsSubscriptions\Support\DashboardMetrics;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * What is about to be charged — how many, and how much.
 *
 * These figures count only subscriptions that will REALLY be billed: active, on PayMe, and
 * with a known amount. A dashboard that sums money it cannot collect is worse than one
 * showing a smaller, true number — so anything blocked is called out separately rather
 * than quietly inflating the total.
 */
class UpcomingCharges extends BaseWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = null;

    public function getHeading(): ?string
    {
        return __('dashboard.upcoming_heading');
    }

    protected function getStats(): array
    {
        $overdue = DashboardMetrics::overdue();
        $today = DashboardMetrics::upcoming(0);
        $week = DashboardMetrics::upcoming(7);
        $month = DashboardMetrics::upcoming(30);

        $blocked = DashboardMetrics::needCardUpdate();
        $unknown = $month['unknown_amount'];

        return [
            // The backlog. With billing not yet running this is the number that matters most,
            // and it must not be buried inside "next 30 days".
            Stat::make(__('dashboard.overdue'), $this->money($overdue['total']))
                ->description(__('dashboard.charges_pending', ['count' => $overdue['count']]))
                ->descriptionIcon('heroicon-m-exclamation-circle')
                ->color($overdue['count'] > 0 ? 'danger' : 'success'),

            Stat::make(__('dashboard.due_today'), $this->money($today['total']))
                ->description(__('dashboard.charges_pending', ['count' => $today['count']]))
                ->descriptionIcon('heroicon-m-clock')
                ->color($today['count'] > 0 ? 'warning' : 'gray'),

            Stat::make(__('dashboard.next_7_days'), $this->money($week['total']))
                ->description(__('dashboard.charges_pending', ['count' => $week['count']]))
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('primary'),

            Stat::make(__('dashboard.next_30_days'), $this->money($month['total']))
                ->description($this->blockedNote($blocked, $unknown)
                    ?? __('dashboard.charges_pending', ['count' => $month['count']]))
                ->descriptionIcon($this->blockedNote($blocked, $unknown)
                    ? 'heroicon-m-exclamation-triangle'
                    : 'heroicon-m-calendar')
                ->color($this->blockedNote($blocked, $unknown) ? 'warning' : 'primary'),
        ];
    }

    /** Money that will NOT be collected, said out loud rather than hidden in the total. */
    private function blockedNote(int $blocked, int $unknown): ?string
    {
        $notes = [];

        if ($blocked > 0) {
            $notes[] = __('dashboard.blocked_card', ['count' => $blocked]);
        }

        if ($unknown > 0) {
            $notes[] = __('dashboard.blocked_amount', ['count' => $unknown]);
        }

        return $notes === [] ? null : implode(' · ', $notes);
    }

    private function money(float $amount): string
    {
        return '₪'.number_format($amount, 2);
    }
}
