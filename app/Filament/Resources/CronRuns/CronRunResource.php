<?php

namespace App\Filament\Resources\CronRuns;

use App\Filament\Resources\CronRuns\Pages\ListCronRuns;
use App\Filament\Resources\CronRuns\Pages\ViewCronRun;
use App\Filament\Resources\CronRuns\Schemas\CronRunInfolist;
use App\Filament\Resources\CronRuns\Tables\CronRunsTable;
use App\Models\CronRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The scheduler's own log — READ ONLY.
 *
 * The scaffold shipped create and edit pages, which are meaningless here: a row is written by
 * the scheduler when a task runs, and the model documents itself as "never updated". "New cron
 * run" invited an admin to invent a run that never happened; Edit invited them to rewrite one
 * that did. A log you can edit proves nothing about whether billing ran.
 */
class CronRunResource extends Resource
{
    protected static ?string $model = CronRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?int $navigationSort = 60;

    public static function getNavigationLabel(): string
    {
        return __('cron.title');
    }

    public static function getModelLabel(): string
    {
        return __('cron.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cron.title');
    }

    public static function infolist(Schema $schema): Schema
    {
        return CronRunInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CronRunsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCronRuns::route('/'),
            'view' => ViewCronRun::route('/{record}'),
        ];
    }
}
