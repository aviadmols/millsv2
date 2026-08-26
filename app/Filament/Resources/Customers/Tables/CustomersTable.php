<?php

namespace App\Filament\Resources\Customers\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('shopify_customer_id')->label(__('customers.shopify_id'))
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable(),
                TextColumn::make('phone')->label(__('customers.phone'))
                    ->searchable(),
                TextColumn::make('first_name')->label(__('customers.first_name'))
                    ->searchable(),
                TextColumn::make('last_name')->label(__('customers.last_name'))
                    ->searchable(),
                TextColumn::make('address1')
                    ->searchable(),
                TextColumn::make('address2')
                    ->searchable(),
                TextColumn::make('city')->label(__('customers.city'))
                    ->searchable(),
                TextColumn::make('province')->label(__('customers.province'))
                    ->searchable(),
                TextColumn::make('country')->label(__('customers.country'))
                    ->searchable(),
                TextColumn::make('zip')->label(__('customers.zip'))
                    ->searchable(),
                TextColumn::make('locale')->label(__('customers.locale'))
                    ->searchable(),
                TextColumn::make('address_pushed_at')->label(__('customers.address_pushed_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('legacy_shopify_gid')->label(__('customers.legacy_id'))
                    ->searchable(),
                TextColumn::make('created_at')->label(__('customers.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label(__('customers.updated_at'))
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
