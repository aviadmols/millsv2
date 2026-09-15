<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\PaymentLedger;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * Customers who paid and have no order — so nothing will be shipped to them.
 *
 * Creating the Shopify order after a charge is deliberately compensating: the money has
 * moved, so a failure there never unwinds the charge. That is the right call for the money
 * and it made the failure silent. Subscription 321 was charged ₪414 at midnight on 11
 * September, Shopify refused the order, and the only trace was a row in the system log —
 * found four days later, by someone looking for something else.
 *
 * So it sits at the top of the home screen, and only when there is something in it: every
 * row is a person who has paid and is waiting for a box that is not coming.
 */
class MissingOrders extends Widget
{
    /** Top of the page, with the other "a real customer is affected right now" alerts. */
    protected static ?int $sort = 0;

    protected string $view = 'filament.widgets.missing-orders';

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        // An empty alert is silence, not an empty box.
        return PaymentLedger::query()->missingOrder()->exists();
    }

    /** @return Collection<int, PaymentLedger> */
    public function getMissing(): Collection
    {
        return PaymentLedger::query()
            ->missingOrder()
            ->with(['subscription.customer'])
            ->latest('executed_at')
            ->limit(50)
            ->get();
    }

    public function subscriptionUrl(PaymentLedger $ledger): ?string
    {
        return $ledger->subscription_id
            ? SubscriptionResource::getUrl('view', ['record' => $ledger->subscription_id])
            : null;
    }
}
