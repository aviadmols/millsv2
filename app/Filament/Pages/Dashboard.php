<?php

namespace App\Filament\Pages;

use Filament\Forms\Components\Radio;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The home tab, with ONE choice that recolours every number on it.
 *
 * Since the Cardcom import the book has two very different sizes: what can actually be
 * charged today (PayMe card on file), and everything that is live including the hundreds
 * still waiting on a card. Both are real questions — "what will we collect" and "what is
 * this migration worth" — and neither answer should require mental arithmetic over a
 * footnote. The selector picks the question; the widgets answer it.
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    public const SCOPE_BILLABLE = 'billable';

    public const SCOPE_ALL = 'all';

    /** The window the period figures are measured over, in days. */
    public const DEFAULT_PERIOD_DAYS = 30;

    /**
     * The periods offered, in days.
     *
     * Each one is compared against the SAME length immediately before it, so the trend
     * arrow always answers "against the equivalent stretch" — a week against the week
     * before, not against a hardcoded month that would make every week look catastrophic.
     *
     * @return array<int, string>
     */
    public static function periods(): array
    {
        return [
            1 => __('dashboard.period_day'),
            2 => __('dashboard.period_2_days'),
            7 => __('dashboard.period_week'),
            30 => __('dashboard.period_month'),
            90 => __('dashboard.period_quarter'),
        ];
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->schema([
                    Radio::make('scope')
                        ->hiddenLabel()
                        ->inline()
                        ->default(self::SCOPE_BILLABLE)
                        ->options([
                            self::SCOPE_BILLABLE => __('dashboard.scope_billable'),
                            self::SCOPE_ALL => __('dashboard.scope_all'),
                        ])
                        ->live(),

                    Radio::make('period')
                        ->label(__('dashboard.period'))
                        ->inline()
                        ->default(self::DEFAULT_PERIOD_DAYS)
                        ->options(self::periods())
                        ->live(),
                ]),
        ]);
    }
}
