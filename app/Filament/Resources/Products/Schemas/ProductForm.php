<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('shopify_product_id')
                    ->required(),
                TextInput::make('title')
                    ->required(),
                TextInput::make('handle'),
                TextInput::make('status')
                    ->required()
                    ->default('active'),
                FileUpload::make('image_url')
                    ->image(),
                Textarea::make('tags')
                    ->columnSpanFull(),
                DateTimePicker::make('shopify_updated_at'),
                DateTimePicker::make('synced_at'),
            ]);
    }
}
