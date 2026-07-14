<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('users.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label(__('users.email'))
                    ->searchable()
                    ->copyable(),

                TextColumn::make('created_at')
                    ->label(__('users.created_at'))
                    ->dateTime('Y-m-d')
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),

                DeleteAction::make()
                    /*
                     * Two ways to lock everyone out of the admin for good, both one click away:
                     * delete yourself while you are logged in, or delete the last account that
                     * exists. Neither is recoverable from the UI — someone would have to open a
                     * shell on the production box to get back in.
                     */
                    ->visible(fn (User $record) => $record->getKey() !== auth()->id()
                        && User::query()->count() > 1)
                    ->requiresConfirmation()
                    ->modalDescription(__('users.delete_confirm')),
            ]);
    }
}
