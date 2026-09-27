<?php

namespace App\Filament\Resources\Subscriptions\Pages;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Subscription;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Creating a subscription by hand.
 *
 * The subscription and its dogs are written as ONE unit. They used to be two writes, and
 * when the second failed the first had already landed: the admin was shown an error and
 * left with a subscription that existed anyway, with no dogs on it — the state that then
 * has nothing to ship and nothing to price. A half-created subscription is worse than none,
 * because it looks finished.
 *
 * Filament's own switch, not a hand-rolled DB::transaction around handleRecordCreation():
 * the relationship save (the dogs) happens OUTSIDE that method, which is exactly the write
 * that failed, so a wrapper there would have protected the half that never broke.
 *
 * `status` is mass-assignment guarded, so a plain create($data) dropped the chosen status
 * and every new subscription landed on the column default, pending. The initial value is
 * set with forceFill — the one sanctioned way in, per HasGuardedStatus.
 */
class CreateSubscription extends CreateRecord
{
    protected static string $resource = SubscriptionResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function handleRecordCreation(array $data): Model
    {
        $status = SubscriptionStatus::tryFrom((string) ($data['status'] ?? '')) ?? SubscriptionStatus::PENDING;
        unset($data['status']);

        $record = new Subscription;
        $record->fill($data);
        $record->forceFill(['status' => $status->value])->save();

        return $record;
    }
}
