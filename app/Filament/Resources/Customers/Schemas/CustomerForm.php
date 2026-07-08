<?php

namespace App\Filament\Resources\Customers\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('shopify_customer_id'),
                TextInput::make('email')
                    ->label('Email address')
                    ->email(),
                TextInput::make('phone')
                    ->tel(),
                TextInput::make('first_name'),
                TextInput::make('last_name'),
                TextInput::make('address1'),
                TextInput::make('address2'),
                TextInput::make('city'),
                TextInput::make('province'),
                TextInput::make('country'),
                TextInput::make('zip'),
                TextInput::make('locale')
                    ->required()
                    ->default('he'),
                DateTimePicker::make('address_pushed_at'),
                Textarea::make('meta')
                    ->columnSpanFull(),
                TextInput::make('legacy_shopify_gid'),
            ]);
    }
}
