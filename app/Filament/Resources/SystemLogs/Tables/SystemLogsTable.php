<?php

namespace App\Filament\Resources\SystemLogs\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SystemLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('logs.time'))
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),
                TextColumn::make('level')
                    ->label(__('logs.level'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => __('logs.level_'.$state))
                    ->color(fn ($state) => match ($state) {
                        'error' => 'danger',
                        'warning' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('category')
                    ->label(__('logs.category'))
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(fn ($state) => __('logs.cat_'.$state)),
                TextColumn::make('message')
                    ->label(__('logs.message'))
                    ->wrap()
                    ->limit(90)
                    ->searchable(),
                TextColumn::make('status_code')
                    ->label(__('logs.status'))
                    ->badge()
                    ->placeholder('—')
                    ->color(fn ($state) => match (true) {
                        $state === null => 'gray',
                        $state >= 500 => 'danger',
                        $state >= 400 => 'warning',
                        default => 'success',
                    })
                    ->toggleable(),
                TextColumn::make('duration_ms')
                    ->label('ms')
                    ->numeric()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('level')
                    ->label(__('logs.level'))
                    ->options([
                        'info' => __('logs.level_info'),
                        'warning' => __('logs.level_warning'),
                        'error' => __('logs.level_error'),
                    ]),
                SelectFilter::make('category')
                    ->label(__('logs.category'))
                    ->options([
                        'api' => __('logs.cat_api'),
                        'storefront' => __('logs.cat_storefront'),
                        'billing' => __('logs.cat_billing'),
                        'shopify' => __('logs.cat_shopify'),
                        'webhook' => __('logs.cat_webhook'),
                        'otp' => __('logs.cat_otp'),
                        'cron' => __('logs.cat_cron'),
                        'admin' => __('logs.cat_admin'),
                        'system' => __('logs.cat_system'),
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
