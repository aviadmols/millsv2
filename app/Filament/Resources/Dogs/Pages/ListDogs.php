<?php

namespace App\Filament\Resources\Dogs\Pages;

use App\Filament\Resources\Dogs\DogResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDogs extends ListRecords
{
    protected static string $resource = DogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
