<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Subscription;
use App\Modules\MillsSubscriptions\Support\DashboardMetrics;
use App\Modules\MillsSubscriptions\Support\VariantResolver;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * The orders that are about to go out: who, when, how much, and what is in the box.
 *
 * Anything already overdue is shown first and flagged — with the biller not yet running,
 * a queue of missed charges is the most important thing on this page, and it would be
 * dishonest to file it quietly under "upcoming".
 */
class UpcomingOrders extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public function getTableHeading(): string
    {
        return __('dashboard.upcoming_orders');
    }

    public function table(Table $table): Table
    {
        return $table
            /*
             * Scoped by the HOME TAB's selector: "all subscriptions" lists the orders of
             * the card-blocked book too. The card wall shows on each such row — the amount
             * column already flags what will not actually charge as things stand.
             */
            ->query(fn () => DashboardMetrics::upcomingQuery(
                30,
                ($this->pageFilters['scope'] ?? Dashboard::SCOPE_BILLABLE) === Dashboard::SCOPE_ALL,
            ))
            ->filters([
                /*
                 * "What ships on the 3rd?" is a packing question, and answering it by
                 * sorting and squinting at a 30-day list is not an answer. One day, its
                 * orders, its total in the summary row.
                 */
                Filter::make('charge_day')
                    ->schema([
                        DatePicker::make('day')->label(__('dashboard.charge_day'))->native(false),
                    ])
                    ->query(fn ($query, array $data) => $query->when(
                        $data['day'] ?? null,
                        fn ($q, $day) => $q->whereDate('next_charge_at', $day),
                    ))
                    ->indicateUsing(fn (array $data) => ($data['day'] ?? null)
                        ? __('dashboard.charge_day').': '.$data['day']
                        : null),
            ])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading(__('dashboard.no_upcoming'))
            ->emptyStateDescription(__('dashboard.no_upcoming_help'))
            ->columns([
                TextColumn::make('next_charge_at')
                    ->label(__('dashboard.charge_date'))
                    ->date('Y-m-d')
                    ->sortable()
                    ->badge()
                    ->color(fn (Subscription $record) => $record->next_charge_at?->isPast() ? 'danger' : 'gray')
                    ->description(fn (Subscription $record) => $record->next_charge_at?->isPast()
                        ? __('dashboard.overdue_by', ['days' => $record->next_charge_at->diffInDays(now())])
                        : $record->next_charge_at?->diffForHumans()),

                TextColumn::make('customer.email')
                    ->label(__('subscriptions.customer'))
                    ->searchable()
                    ->description(fn (Subscription $record) => $record->customer?->fullName()),

                TextColumn::make('products')
                    ->label(__('subscriptions.products'))
                    ->state(fn (Subscription $record) => self::products($record))
                    ->listWithLineBreaks()
                    ->limitList(2)
                    ->placeholder(__('subscriptions.no_products')),

                TextColumn::make('frequency_months')
                    ->label(__('subscriptions.frequency'))
                    ->formatStateUsing(fn (int $state) => $state === 2
                        ? __('subscriptions.every_2_months')
                        : __('subscriptions.monthly'))
                    ->badge()
                    ->color('gray'),

                TextColumn::make('next_charge_amount')
                    ->label(__('dashboard.amount'))
                    ->money('ILS')
                    ->weight('bold')
                    ->alignEnd()
                    // An amount we do not know is an amount that will NOT be charged.
                    ->placeholder(__('dashboard.amount_missing'))
                    ->color(fn (Subscription $record) => empty($record->next_charge_amount) ? 'danger' : null)
                    ->summarize(
                        Sum::make()
                            ->label(__('dashboard.total'))
                            ->money('ILS'),
                    ),
            ])
            ->recordActions([
                Action::make('view')
                    ->label(__('dashboard.open'))
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->url(fn (Subscription $record) => SubscriptionResource::getUrl('view', ['record' => $record])),
            ]);
    }

    /** @return list<string> */
    private static function products(Subscription $subscription): array
    {
        $lines = [];

        foreach ($subscription->dogs as $dog) {
            foreach (VariantResolver::lines($dog->selected_variants) as $line) {
                $lines[] = trim(($line['title'] ?? '—').' · '.($line['grams'] ?? '?').'g');
            }
        }

        return $lines;
    }
}
