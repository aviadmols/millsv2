<?php

namespace App\Filament\Resources\Subscriptions\Pages;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use Filament\Resources\Pages\CreateRecord;

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
 */
class CreateSubscription extends CreateRecord
{
    protected static string $resource = SubscriptionResource::class;

    protected ?bool $hasDatabaseTransactions = true;
}
