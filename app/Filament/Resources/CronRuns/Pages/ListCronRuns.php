<?php

namespace App\Filament\Resources\CronRuns\Pages;

use App\Filament\Resources\CronRuns\CronRunResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCronRuns extends ListRecords
{
    protected static string $resource = CronRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
