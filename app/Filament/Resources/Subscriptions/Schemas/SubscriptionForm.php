<?php

namespace App\Filament\Resources\Subscriptions\Schemas;

use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SubscriptionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('customer_id')
                    ->relationship('customer', 'id')
                    ->required(),
                Select::make('status')
                    ->options(SubscriptionStatus::class)
                    ->default('pending')
                    ->required(),
                Select::make('payment_state')
                    ->options(PaymentState::class)
                    ->default('needs_card_update')
                    ->required(),
                TextInput::make('frequency_months')
                    ->required()
                    ->numeric()
                    ->default(1),
                DateTimePicker::make('next_charge_at'),
                TextInput::make('original_order_id'),
                TextInput::make('draft_order_id'),
                TextInput::make('attempt_count')
                    ->required()
                    ->numeric()
                    ->default(0),
                DateTimePicker::make('next_retry_at'),
                Textarea::make('meta')
                    ->columnSpanFull(),
                TextInput::make('legacy_shopify_gid'),
            ]);
    }
}
