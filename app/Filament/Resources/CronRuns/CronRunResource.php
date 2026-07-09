<?php

namespace App\Filament\Resources\CronRuns;

use App\Filament\Resources\CronRuns\Pages\CreateCronRun;
use App\Filament\Resources\CronRuns\Pages\EditCronRun;
use App\Filament\Resources\CronRuns\Pages\ListCronRuns;
use App\Filament\Resources\CronRuns\Schemas\CronRunForm;
use App\Filament\Resources\CronRuns\Tables\CronRunsTable;
use App\Models\CronRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CronRunResource extends Resource
{
    protected static ?string $model = CronRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return CronRunForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CronRunsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCronRuns::route('/'),
            'create' => CreateCronRun::route('/create'),
            'edit' => EditCronRun::route('/{record}/edit'),
        ];
    }
}
