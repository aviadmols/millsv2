<?php

namespace App\Filament\Resources\CronRuns\Pages;

use App\Filament\Resources\CronRuns\CronRunResource;
use Filament\Resources\Pages\ListRecords;

class ListCronRuns extends ListRecords
{
    protected static string $resource = CronRunResource::class;

    /** No "New cron run": a run is something the scheduler did, not something you can add. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
