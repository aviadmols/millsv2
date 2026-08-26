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
                TextColumn::make('customer.id')->label(__('dogs.customer'))
                    ->searchable(),
                TextColumn::make('subscription.id')->label(__('dogs.subscription'))
                    ->searchable(),
                TextColumn::make('name')->label(__('dogs.name'))
                    ->searchable(),
                TextColumn::make('sex')->label(__('dogs.sex'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('age')->label(__('dogs.age'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('weight')->label(__('dogs.weight'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('activity')->label(__('dogs.activity'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('body')->label(__('dogs.body'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('calories_per_day')->label(__('dogs.calories_per_day'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('birth_date')->label(__('dogs.birth_date'))
                    ->date()
                    ->sortable(),
                IconColumn::make('double_food')->label(__('dogs.double_food'))
                    ->boolean(),
                TextColumn::make('avatar')->label(__('dogs.avatar'))
                    ->searchable(),
                TextColumn::make('status')->label(__('dogs.status'))
                    ->searchable(),
                TextColumn::make('subscription_status')->label(__('dogs.subscription_status'))
                    ->searchable(),
                TextColumn::make('legacy_shopify_gid')->label(__('dogs.legacy_id'))
                    ->searchable(),
                TextColumn::make('created_at')->label(__('dogs.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label(__('dogs.updated_at'))
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
