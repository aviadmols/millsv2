<?php

namespace App\Filament\Resources\Activity\Pages;

use App\Filament\Resources\Activity\ActivityResource;
use Filament\Resources\Pages\ListRecords;

class ListActivity extends ListRecords
{
    protected static string $resource = ActivityResource::class;

    /** A record of what happened is not something you add to by hand. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
