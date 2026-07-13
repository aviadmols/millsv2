<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Subscription;
use App\Modules\MillsSubscriptions\Support\DashboardMetrics;
use App\Modules\MillsSubscriptions\Support\VariantResolver;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
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
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public function getTableHeading(): string
    {
        return __('dashboard.upcoming_orders');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => DashboardMetrics::upcomingQuery(30))
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
                        \Filament\Tables\Columns\Summarizers\Sum::make()
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
