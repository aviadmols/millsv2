<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\SystemLog;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                // You cannot delete yourself, and you cannot delete the last account. Either
                // one locks every human out of the admin, with no way back in from the UI.
                ->visible(fn (User $record) => $record->getKey() !== auth()->id()
                    && User::query()->count() > 1)
                ->requiresConfirmation()
                ->modalDescription(__('users.delete_confirm')),
        ];
    }

    protected function afterSave(): void
    {
        SystemLog::info('admin', 'an admin login was changed', [
            'user_id' => $this->record->getKey(),
            'changed_by' => auth()->id(),
            // Never the password itself, hashed or otherwise — only that it moved.
            'password_changed' => $this->record->wasChanged('password'),
        ]);
    }
}
