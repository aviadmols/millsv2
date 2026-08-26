<?php

namespace App\Filament\Resources\PaymentLedgers\Schemas;

use App\Modules\MillsSubscriptions\Enums\LedgerStatus;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PaymentLedgerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            // One field per row, full width. Filament defaults a resource form's schema to
            // two columns, which left every field at half width with dead space beside it.
            ->columns(1)
            ->components([
                Select::make('subscription_id')->label(__('ledgers.subscription'))
                    ->relationship('subscription', 'id'),
                Select::make('customer_id')->label(__('ledgers.customer'))
                    ->relationship('customer', 'id'),
                Select::make('payment_method_id')->label(__('ledgers.payment_method'))
                    ->relationship('paymentMethod', 'id'),
                TextInput::make('context')->label(__('ledgers.context'))
                    ->required(),
                TextInput::make('idempotency_key')->label(__('ledgers.idempotency_key'))
                    ->required(),
                Select::make('status')->label(__('ledgers.status'))
                    ->options(LedgerStatus::class)
                    ->default('pending')
                    ->required(),
                TextInput::make('amount')->label(__('ledgers.amount'))
                    ->numeric(),
                TextInput::make('currency')->label(__('ledgers.currency'))
                    ->required()
                    ->default('ILS'),
                TextInput::make('payme_transaction_id')->label(__('ledgers.payme_transaction_id')),
                TextInput::make('shopify_order_id')->label(__('ledgers.shopify_order_id')),
                TextInput::make('draft_order_id')->label(__('ledgers.draft_order_id')),
                TextInput::make('failure_code')->label(__('ledgers.failure_code')),
                Textarea::make('failure_message')->label(__('ledgers.failure_message'))
                    ->columnSpanFull(),
                Textarea::make('raw_response_masked')->label(__('ledgers.raw_response'))
                    ->columnSpanFull(),
                DateTimePicker::make('executed_at')->label(__('ledgers.executed_at')),
            ]);
    }
}
