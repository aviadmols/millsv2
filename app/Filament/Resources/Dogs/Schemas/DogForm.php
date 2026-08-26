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
                Select::make('customer_id')->label(__('dogs.customer'))
                    ->relationship('customer', 'id')
                    ->required(),
                Select::make('subscription_id')->label(__('dogs.subscription'))
                    ->relationship('subscription', 'id'),
                TextInput::make('name')->label(__('dogs.name')),
                TextInput::make('sex')->label(__('dogs.sex'))
                    ->numeric(),
                TextInput::make('age')->label(__('dogs.age'))
                    ->numeric(),
                TextInput::make('weight')->label(__('dogs.weight'))
                    ->numeric(),
                AllergySelect::make()
                    ->columnSpanFull(),
                TextInput::make('activity')->label(__('dogs.activity'))
                    ->numeric(),
                TextInput::make('body')->label(__('dogs.body'))
                    ->numeric(),
                TextInput::make('calories_per_day')->label(__('dogs.calories_per_day'))
                    ->numeric(),
                DatePicker::make('birth_date')->label(__('dogs.birth_date')),
                Toggle::make('double_food')->label(__('dogs.double_food'))
                    ->required(),
                TextInput::make('avatar')->label(__('dogs.avatar')),
                TextInput::make('status')->label(__('dogs.status'))
                    ->required()
                    ->default('active'),
                TextInput::make('subscription_status')->label(__('dogs.subscription_status')),
                Textarea::make('selected_variants')->label(__('dogs.selected_variants'))
                    ->columnSpanFull(),
                Textarea::make('addons_products')->label(__('dogs.addons_products'))
                    ->columnSpanFull(),
                TextInput::make('legacy_shopify_gid')->label(__('dogs.legacy_id')),
            ]);
    }
}
