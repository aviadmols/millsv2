<?php

namespace App\Filament\Resources\CronRuns\Pages;

use App\Filament\Resources\CronRuns\CronRunResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCronRun extends EditRecord
{
    protected static string $resource = CronRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
