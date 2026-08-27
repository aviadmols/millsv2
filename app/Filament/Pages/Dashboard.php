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
                ]),
        ]);
    }
}
