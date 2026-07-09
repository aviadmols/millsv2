<?php

namespace App\Filament\Resources\Subscriptions\Schemas;

use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The full subscription view — subscriber details + subscription + dogs + billing
 * history, modeled on the RECHARGE / v1 subscription page so nothing is lost.
 */
class SubscriptionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('subscriptions.owner_details'))
                ->columns(2)
                ->schema([
                    TextEntry::make('customer_name')
                        ->label(__('subscriptions.name'))
                        ->state(fn ($record) => $record->customer?->fullName() ?? '—'),
                    TextEntry::make('customer.email')->label(__('subscriptions.email'))->copyable(),
                    TextEntry::make('customer.phone')->label(__('subscriptions.phone'))->placeholder('—'),
                    TextEntry::make('customer.shopify_customer_id')->label(__('subscriptions.shopify_id'))->placeholder('—'),
                    TextEntry::make('address')
                        ->label(__('subscriptions.address'))
                        ->columnSpanFull()
                        ->state(fn ($record) => trim(implode(', ', array_filter([
                            $record->customer?->address1,
                            $record->customer?->address2,
                            $record->customer?->city,
                            $record->customer?->zip,
                            $record->customer?->country,
                        ]))) ?: '—'),
                ]),

            Section::make(__('subscriptions.subscription_details'))
                ->columns(3)
                ->schema([
                    TextEntry::make('status')
                        ->label(__('subscriptions.status'))
                        ->badge()
                        ->formatStateUsing(fn (SubscriptionStatus $state) => __('subscriptions.status_'.$state->value))
                        ->color(fn (SubscriptionStatus $state) => match ($state) {
                            SubscriptionStatus::ACTIVE => 'success',
                            SubscriptionStatus::PENDING, SubscriptionStatus::PAUSED => 'warning',
                            SubscriptionStatus::PAST_DUE => 'danger',
                            SubscriptionStatus::CANCELLED => 'gray',
                        }),
                    TextEntry::make('payment_state')
                        ->label(__('subscriptions.payment'))
                        ->badge()
                        ->formatStateUsing(fn (PaymentState $state) => __('subscriptions.pay_'.$state->value))
                        ->color(fn (PaymentState $state) => $state === PaymentState::PAYME ? 'success' : 'warning'),
                    TextEntry::make('frequency_months')
                        ->label(__('subscriptions.frequency'))
                        ->formatStateUsing(fn (int $state) => $state === 2 ? __('subscriptions.every_2_months') : __('subscriptions.monthly')),
                    TextEntry::make('next_charge_at')->label(__('subscriptions.next_charge'))->dateTime('Y-m-d'),
                    TextEntry::make('original_order_id')->label(__('subscriptions.original_order'))->placeholder('—'),
                    TextEntry::make('draft_order_id')->label(__('subscriptions.draft_order'))->placeholder('—'),
                ]),

            Section::make(__('subscriptions.dogs'))
                ->schema([
                    RepeatableEntry::make('dogs')
                        ->hiddenLabel()
                        ->columns(4)
                        ->schema([
                            TextEntry::make('name')->label(__('subscriptions.name')),
                            TextEntry::make('weight')->label('kg')->placeholder('—'),
                            TextEntry::make('age')->label('age')->placeholder('—'),
                            TextEntry::make('allergies')->placeholder('—'),
                        ]),
                ]),

            Section::make(__('subscriptions.billing_history'))
                ->schema([
                    RepeatableEntry::make('ledgerEntries')
                        ->hiddenLabel()
                        ->columns(4)
                        ->schema([
                            TextEntry::make('executed_at')->label('date')->dateTime('Y-m-d H:i')->placeholder('—'),
                            TextEntry::make('amount')->money('ILS')->placeholder('—'),
                            TextEntry::make('status')->badge()
                                ->color(fn ($state) => in_array((string) ($state->value ?? $state), ['succeeded'], true) ? 'success' : 'danger'),
                            TextEntry::make('payme_transaction_id')->label('tx')->placeholder('—')->limit(20),
                        ]),
                ]),
        ]);
    }
}
