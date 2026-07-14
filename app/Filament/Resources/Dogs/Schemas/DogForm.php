<?php

namespace App\Filament\Resources\Dogs\Schemas;

use App\Filament\Forms\AllergySelect;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class DogForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            // One field per row, full width. Filament defaults a resource form's schema to
            // two columns, which left every field at half width with dead space beside it.
            ->columns(1)
            ->components([
                Select::make('customer_id')
                    ->relationship('customer', 'id')
                    ->required(),
                Select::make('subscription_id')
                    ->relationship('subscription', 'id'),
                TextInput::make('name'),
                TextInput::make('sex')
                    ->numeric(),
                TextInput::make('age')
                    ->numeric(),
                TextInput::make('weight')
                    ->numeric(),
                AllergySelect::make()
                    ->columnSpanFull(),
                TextInput::make('activity')
                    ->numeric(),
                TextInput::make('body')
                    ->numeric(),
                TextInput::make('calories_per_day')
                    ->numeric(),
                DatePicker::make('birth_date'),
                Toggle::make('double_food')
                    ->required(),
                TextInput::make('avatar'),
                TextInput::make('status')
                    ->required()
                    ->default('active'),
                TextInput::make('subscription_status'),
                Textarea::make('selected_variants')
                    ->columnSpanFull(),
                Textarea::make('addons_products')
                    ->columnSpanFull(),
                TextInput::make('legacy_shopify_gid'),
            ]);
    }
}
