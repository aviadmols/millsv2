<?php

namespace App\Filament\Widgets;

use App\Modules\MillsSubscriptions\Support\DashboardMetrics;
use Filament\Widgets\Widget;

/**
 * What is about to be charged — how many, and how much.
 *
 * By default these figures count only subscriptions that will REALLY be billed: active,
 * on PayMe, with a known amount. A dashboard that sums money it cannot collect is worse
 * than one showing a smaller, true number.
 *
 * The toggle asks the OTHER honest question: what would this book be worth if everyone
 * waiting on a card updated it? With 558 imported Cardcom customers in exactly that
 * state, that number is the size of the migration — but it is shown as potential, by
 * choice, never silently mixed into the real total.
 */
class UpcomingCharges extends Widget
{
    protected static ?int $sort = 2;

    protected string $view = 'filament.widgets.upcoming-charges';

    protected int|string|array $columnSpan = 'full';

    /** The toggle: include ACTIVE subscriptions still waiting on a card. */
    public bool $includeBlocked = false;

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $include = $this->includeBlocked;

        $month = DashboardMetrics::upcoming(30, $include);

        return [
            'stats' => [
                [
                    'label' => __('dashboard.overdue'),
                    'metrics' => $overdue = DashboardMetrics::overdue($include),
                    'icon' => 'heroicon-m-exclamation-circle',
                    'tone' => $overdue['count'] > 0 ? 'bad' : 'ok',
                ],
                [
                    'label' => __('dashboard.due_today'),
                    'metrics' => $today = DashboardMetrics::upcoming(0, $include),
                    'icon' => 'heroicon-m-clock',
                    'tone' => $today['count'] > 0 ? 'warn' : 'neutral',
                ],
                [
                    'label' => __('dashboard.next_7_days'),
                    'metrics' => DashboardMetrics::upcoming(7, $include),
                    'icon' => 'heroicon-m-calendar-days',
                    'tone' => 'neutral',
                ],
                [
                    'label' => __('dashboard.next_30_days'),
                    'metrics' => $month,
                    'icon' => 'heroicon-m-calendar',
                    'tone' => 'neutral',
                ],
            ],
            'blocked' => DashboardMetrics::needCardUpdate(),
            'unknown' => $month['unknown_amount'],
        ];
    }
}
