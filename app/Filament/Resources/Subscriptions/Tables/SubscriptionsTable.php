<?php

namespace App\Filament\Resources\Subscriptions\Tables;

use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Modules\MillsSubscriptions\Services\SubscriptionActions;
use Carbon\Carbon;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Throwable;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SubscriptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

                TextColumn::make('customer.full_name')
                    ->label(__('subscriptions.customer'))
                    ->getStateUsing(fn ($record) => $record->customer?->fullName() ?? '—')
                    ->description(fn ($record) => $record->customer?->email)
                    ->searchable(query: fn ($query, string $search) => $query->whereHas(
                        'customer',
                        fn ($q) => $q->where('email', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                    ))
                    ->weight('bold'),

                TextColumn::make('status')
                    ->label(__('subscriptions.status'))
                    ->badge()
                    ->formatStateUsing(fn (SubscriptionStatus $state) => __('subscriptions.status_'.$state->value))
                    ->color(fn (SubscriptionStatus $state) => match ($state) {
                        SubscriptionStatus::ACTIVE => 'success',
                        SubscriptionStatus::PENDING, SubscriptionStatus::PAUSED => 'warning',
                        SubscriptionStatus::PAST_DUE => 'danger',
                        SubscriptionStatus::CANCELLED => 'gray',
                    }),

                TextColumn::make('payment_state')
                    ->label(__('subscriptions.payment'))
                    ->badge()
                    ->formatStateUsing(fn (PaymentState $state) => __('subscriptions.pay_'.$state->value))
                    ->color(fn (PaymentState $state) => match ($state) {
                        PaymentState::PAYME => 'success',
                        PaymentState::NEEDS_CARD_UPDATE => 'warning',
                        PaymentState::NO_CHARGE => 'gray',
                    }),

                TextColumn::make('frequency_months')
                    ->label(__('subscriptions.frequency'))
                    ->formatStateUsing(fn (int $state) => $state === 2 ? __('subscriptions.every_2_months') : __('subscriptions.monthly')),

                TextColumn::make('next_charge_at')
                    ->label(__('subscriptions.next_charge'))
                    ->dateTime('Y-m-d')
                    ->sortable(),

                TextColumn::make('dogs_count')
                    ->label(__('subscriptions.dogs'))
                    ->counts('dogs')
                    ->badge()
                    ->color('gray'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::bulkChangeChargeDate(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Move the next charge date of every selected subscription at once — e.g. bring the whole
     * week forward before a holiday. Either by N days (negative = earlier) or to one date.
     *
     * Each one goes through SubscriptionActions::changeNextChargeDate, so each is logged on
     * its own timeline. A cancelled subscription, or one whose new date would be today or
     * earlier (charged on the spot by the next dispatch), is skipped and counted, never forced.
     */
    private static function bulkChangeChargeDate(): BulkAction
    {
        return BulkAction::make('bulkChangeChargeDate')
            ->label(__('subscriptions.bulk_change_charge_date'))
            ->icon(Heroicon::OutlinedCalendar)
            ->modalHeading(__('subscriptions.bulk_change_charge_date'))
            ->modalDescription(__('subscriptions.bulk_change_charge_date_description'))
            ->schema([
                Radio::make('mode')
                    ->label(__('subscriptions.bulk_mode'))
                    ->options([
                        'shift' => __('subscriptions.bulk_mode_shift'),
                        'set' => __('subscriptions.bulk_mode_set'),
                    ])
                    ->default('shift')
                    ->required()
                    ->live(),
                TextInput::make('days')
                    ->label(__('subscriptions.bulk_days'))
                    ->helperText(__('subscriptions.bulk_days_help'))
                    ->integer()
                    ->minValue(-60)
                    ->maxValue(120)
                    ->required(fn (Get $get) => $get('mode') === 'shift')
                    ->visible(fn (Get $get) => $get('mode') === 'shift'),
                DatePicker::make('date')
                    ->label(__('subscriptions.change_charge_date_field'))
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->minDate(now()->addDay()->startOfDay())
                    ->required(fn (Get $get) => $get('mode') === 'set')
                    ->visible(fn (Get $get) => $get('mode') === 'set'),
            ])
            ->action(function (Collection $records, array $data): void {
                $actions = app(SubscriptionActions::class);
                $done = 0;
                $skipped = 0;

                foreach ($records as $subscription) {
                    /** @var Subscription $subscription */
                    if ($subscription->status === SubscriptionStatus::CANCELLED) {
                        $skipped++;

                        continue;
                    }

                    $date = $data['mode'] === 'set'
                        ? Carbon::parse($data['date'])
                        : ($subscription->next_charge_at?->copy()->addDays((int) $data['days']));

                    if ($date === null || ! $date->copy()->startOfDay()->greaterThan(now()->endOfDay())) {
                        $skipped++;

                        continue;
                    }

                    try {
                        $actions->changeNextChargeDate($subscription, $date);
                        $done++;
                    } catch (Throwable) {
                        $skipped++;
                    }
                }

                Notification::make()
                    ->title(__('subscriptions.bulk_change_charge_date_ok', ['done' => $done, 'skipped' => $skipped]))
                    ->{$skipped > 0 ? 'warning' : 'success'}()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}
