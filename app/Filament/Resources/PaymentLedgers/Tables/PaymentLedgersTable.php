<?php

namespace App\Filament\Resources\PaymentLedgers\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentLedgersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('subscription.id')
                    ->searchable(),
                TextColumn::make('customer.id')
                    ->searchable(),
                TextColumn::make('paymentMethod.id')
                    ->searchable(),
                TextColumn::make('context')
                    ->searchable(),
                TextColumn::make('idempotency_key')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->searchable(),
                TextColumn::make('amount')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('currency')
                    ->searchable(),
                TextColumn::make('payme_transaction_id')
                    ->searchable(),
                TextColumn::make('shopify_order_id')
                    ->searchable(),
                TextColumn::make('draft_order_id')
                    ->searchable(),
                TextColumn::make('failure_code')
                    ->searchable(),
                TextColumn::make('executed_at')
                    ->dateTime()
                    ->sortable(),
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
