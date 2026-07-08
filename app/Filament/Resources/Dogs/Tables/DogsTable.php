<?php

namespace App\Filament\Resources\Dogs\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('customer.id')
                    ->searchable(),
                TextColumn::make('subscription.id')
                    ->searchable(),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('sex')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('age')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('weight')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('activity')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('body')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('calories_per_day')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('birth_date')
                    ->date()
                    ->sortable(),
                IconColumn::make('double_food')
                    ->boolean(),
                TextColumn::make('avatar')
                    ->searchable(),
                TextColumn::make('status')
                    ->searchable(),
                TextColumn::make('subscription_status')
                    ->searchable(),
                TextColumn::make('legacy_shopify_gid')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
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
