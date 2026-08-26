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
            // One field per row, full width. Filament defaults a resource form's schema to
            // two columns, which left every field at half width with dead space beside it.
            ->columns(1)
            ->components([
                TextInput::make('shopify_customer_id')->label(__('customers.shopify_id')),
                TextInput::make('email')
                    ->label('Email address')
                    ->email(),
                TextInput::make('phone')->label(__('customers.phone'))
                    ->tel(),
                TextInput::make('first_name')->label(__('customers.first_name')),
                TextInput::make('last_name')->label(__('customers.last_name')),
                TextInput::make('address1'),
                TextInput::make('address2'),
                TextInput::make('city')->label(__('customers.city')),
                TextInput::make('province')->label(__('customers.province')),
                TextInput::make('country')->label(__('customers.country')),
                TextInput::make('zip')->label(__('customers.zip')),
                TextInput::make('locale')->label(__('customers.locale'))
                    ->required()
                    ->default('he'),
                DateTimePicker::make('address_pushed_at')->label(__('customers.address_pushed_at')),
                Textarea::make('meta')->label(__('customers.meta'))
                    ->columnSpanFull(),
                TextInput::make('legacy_shopify_gid')->label(__('customers.legacy_id')),
            ]);
    }
}
