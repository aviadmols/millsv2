<?php

namespace App\Filament\Resources\Dogs;

use App\Filament\Resources\Dogs\Pages\CreateDog;
use App\Filament\Resources\Dogs\Pages\EditDog;
use App\Filament\Resources\Dogs\Pages\ListDogs;
use App\Filament\Resources\Dogs\Schemas\DogForm;
use App\Filament\Resources\Dogs\Tables\DogsTable;
use App\Models\Dog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class DogResource extends Resource
{
    protected static ?string $model = Dog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return DogForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DogsTable::configure($table);
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
            'index' => ListDogs::route('/'),
            'create' => CreateDog::route('/create'),
            'edit' => EditDog::route('/{record}/edit'),
        ];
    }
}
