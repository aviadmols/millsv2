<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\SystemLog;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * Creating a login is a security event, so it is recorded as one.
     *
     * There are no roles here — a new user can read every customer, see every order, and press
     * "charge now". Who was given that, and by whom, is worth being able to answer later.
     */
    protected function afterCreate(): void
    {
        SystemLog::warning('admin', 'a new admin login was created', [
            'new_user_id' => $this->record->getKey(),
            'new_user_email' => $this->record->email,
            'created_by' => auth()->id(),
        ]);
    }
}
