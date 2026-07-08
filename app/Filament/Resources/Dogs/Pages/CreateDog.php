<?php

namespace App\Filament\Resources\Dogs\Pages;

use App\Filament\Resources\Dogs\DogResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDog extends CreateRecord
{
    protected static string $resource = DogResource::class;
}
