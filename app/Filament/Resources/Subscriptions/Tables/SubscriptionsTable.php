<?php

namespace App\Filament\Resources\Subscriptions\Tables;

use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use Filament\Actions\BulkActionGroup;
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
                    ->color(fn (PaymentState $state) => $state === PaymentState::PAYME ? 'success' : 'warning'),

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
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
