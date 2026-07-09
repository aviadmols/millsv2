<?php

namespace App\Filament\Resources\CronRuns\Pages;

use App\Filament\Resources\CronRuns\CronRunResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCronRun extends CreateRecord
{
    protected static string $resource = CronRunResource::class;
}
