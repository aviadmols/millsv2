<?php

namespace App\Filament\Resources\SystemLogs;

use App\Filament\Resources\SystemLogs\Pages\ListSystemLogs;
use App\Filament\Resources\SystemLogs\Pages\ViewSystemLog;
use App\Filament\Resources\SystemLogs\Schemas\SystemLogInfolist;
use App\Filament\Resources\SystemLogs\Tables\SystemLogsTable;
use App\Models\SystemLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Read-only viewer over system_logs — the one screen to see everything the system
 * did (API calls, billing, Shopify, webhooks, OTP, cron). Rows are pruned to
 * config('mills.logging.retention_days') days by logs:prune.
 */
class SystemLogResource extends Resource
{
    protected static ?string $model = SystemLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 95;

    public static function getNavigationLabel(): string
    {
        return __('logs.title');
    }

    public static function getModelLabel(): string
    {
        return __('logs.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('logs.title');
    }

    public static function infolist(Schema $schema): Schema
    {
        return SystemLogInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SystemLogsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSystemLogs::route('/'),
            'view' => ViewSystemLog::route('/{record}'),
        ];
    }
}
