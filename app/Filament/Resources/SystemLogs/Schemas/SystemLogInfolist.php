<?php

namespace App\Filament\Resources\SystemLogs\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SystemLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(3)
                ->schema([
                    TextEntry::make('created_at')->label(__('logs.time'))->dateTime('Y-m-d H:i:s'),
                    TextEntry::make('level')
                        ->label(__('logs.level'))
                        ->badge()
                        ->formatStateUsing(fn ($state) => __('logs.level_'.$state))
                        ->color(fn ($state) => match ($state) {
                            'error' => 'danger',
                            'warning' => 'warning',
                            default => 'gray',
                        }),
                    TextEntry::make('category')
                        ->label(__('logs.category'))
                        ->badge()
                        ->color('info')
                        ->formatStateUsing(fn ($state) => __('logs.cat_'.$state)),

                    TextEntry::make('message')->label(__('logs.message'))->columnSpanFull(),

                    TextEntry::make('method')->label(__('logs.method'))->placeholder('—'),
                    TextEntry::make('path')->label(__('logs.path'))->placeholder('—'),
                    TextEntry::make('status_code')->label(__('logs.status'))->placeholder('—'),

                    TextEntry::make('duration_ms')->label('ms')->placeholder('—'),
                    TextEntry::make('subscription_id')->label('Subscription')->placeholder('—'),
                    TextEntry::make('customer_id')->label('Customer')->placeholder('—'),
                ]),

            Section::make(__('logs.context'))
                ->visible(fn ($record) => ! empty($record->context))
                ->schema([
                    TextEntry::make('context')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->fontFamily('mono')
                        ->formatStateUsing(fn ($state) => is_array($state)
                            ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                            : (string) $state),
                ]),
        ]);
    }
}
