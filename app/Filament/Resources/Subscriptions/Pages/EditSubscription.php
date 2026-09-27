<?php

namespace App\Filament\Resources\Subscriptions\Pages;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Subscription;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Editing a subscription by hand.
 *
 * `status` is mass-assignment guarded on the model, so Filament's update($data) dropped it
 * without a word: the admin picked "active", saved, got a success toast, and the row stayed
 * pending (2026-09-27, subscription 992). The status is pulled out of the form data and
 * moved through transitionTo(), so the change is guarded, recorded on the timeline, and an
 * illegal move is refused on the field instead of silently ignored.
 */
class EditSubscription extends EditRecord
{
    protected static string $resource = SubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /** @param  Subscription  $record */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $to = isset($data['status']) ? SubscriptionStatus::tryFrom((string) $data['status']) : null;
        unset($data['status']);

        // Checked BEFORE anything is written, so a refused status leaves the rest unsaved too.
        if ($to !== null) {
            $from = $record->currentStatus();
            $legal = collect($record->allowedTransitions()[$from->value] ?? [])
                ->contains(fn (SubscriptionStatus $s) => $s === $to);

            if ($from !== $to && ! $legal) {
                throw ValidationException::withMessages([
                    'data.status' => __('subscriptions.status_illegal_transition', [
                        'from' => __('subscriptions.status_'.$from->value),
                        'to' => __('subscriptions.status_'.$to->value),
                    ]),
                ]);
            }
        }

        $record->update($data);

        if ($to !== null) {
            $record->transitionTo($to, ['source' => 'admin_edit']);
        }

        return $record;
    }
}
