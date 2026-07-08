<?php

namespace App\Filament\Resources\Dogs\Pages;

use App\Filament\Resources\Dogs\DogResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDog extends EditRecord
{
    protected static string $resource = DogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
