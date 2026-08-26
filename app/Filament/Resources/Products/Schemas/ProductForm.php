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
            // One field per row, full width. Filament defaults a resource form's schema to
            // two columns, which left every field at half width with dead space beside it.
            ->columns(1)
            ->components([
                TextInput::make('shopify_product_id')->label(__('products.shopify_id'))
                    ->required(),
                TextInput::make('title')->label(__('products.product_title'))
                    ->required(),
                TextInput::make('handle')->label(__('products.handle')),
                TextInput::make('status')->label(__('products.status'))
                    ->required()
                    ->default('active'),
                FileUpload::make('image_url')
                    ->image(),
                Textarea::make('tags')->label(__('products.tags'))
                    ->columnSpanFull(),
                DateTimePicker::make('shopify_updated_at')->label(__('products.shopify_updated_at')),
                DateTimePicker::make('synced_at')->label(__('products.synced_at')),
            ]);
    }
}
