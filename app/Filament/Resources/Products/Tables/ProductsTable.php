<?php

namespace App\Filament\Resources\Products\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('shopify_product_id')->label(__('products.shopify_id'))
                    ->searchable(),
                TextColumn::make('title')->label(__('products.product_title'))
                    ->searchable(),
                TextColumn::make('handle')->label(__('products.handle'))
                    ->searchable(),
                TextColumn::make('status')->label(__('products.status'))
                    ->searchable(),
                ImageColumn::make('image_url')->label(__('products.image')),
                TextColumn::make('shopify_updated_at')->label(__('products.shopify_updated_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('synced_at')->label(__('products.synced_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')->label(__('products.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label(__('products.updated_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
