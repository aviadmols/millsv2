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
            ->components([
                Select::make('subscription_id')
                    ->relationship('subscription', 'id'),
                Select::make('customer_id')
                    ->relationship('customer', 'id'),
                Select::make('payment_method_id')
                    ->relationship('paymentMethod', 'id'),
                TextInput::make('context')
                    ->required(),
                TextInput::make('idempotency_key')
                    ->required(),
                Select::make('status')
                    ->options(LedgerStatus::class)
                    ->default('pending')
                    ->required(),
                TextInput::make('amount')
                    ->numeric(),
                TextInput::make('currency')
                    ->required()
                    ->default('ILS'),
                TextInput::make('payme_transaction_id'),
                TextInput::make('shopify_order_id'),
                TextInput::make('draft_order_id'),
                TextInput::make('failure_code'),
                Textarea::make('failure_message')
                    ->columnSpanFull(),
                Textarea::make('raw_response_masked')
                    ->columnSpanFull(),
                DateTimePicker::make('executed_at'),
            ]);
    }
}
