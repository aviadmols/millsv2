<?php

namespace App\Filament\Widgets;

use App\Models\Customer;
use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Support\Timeline;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Customers who moved to PayMe and are STILL being charged by Cardcom.
 *
 * Saving a card here makes us start billing them — but their old recurring charge lives on in
 * Cardcom, and removing it is a manual act in Cardcom's own admin that no API performs. Until
 * a human does it, the customer pays twice. That is why this sits at the top of the
 * dashboard and refuses to be dismissed any other way: each row is a real person being
 * double-billed right now, and "אישור" is a named admin stating they removed the charge.
 */
class CardcomHandoff extends Widget
{
    /** Right under the system-status panel: this is money leaving customers' accounts. */
    protected static ?int $sort = 1;

    protected string $view = 'filament.widgets.cardcom-handoff';

    protected int|string|array $columnSpan = 'full';

    /** Poll: a card update mid-meeting should appear without a refresh. */
    protected static ?string $pollingInterval = '60s';

    /** @return Builder<Customer> */
    public static function pendingQuery(): Builder
    {
        return Customer::query()
            ->whereNull('cardcom_removed_at')
            /*
             * Only people whose SUBSCRIPTION came from the old system.
             *
             * The marker has to be on the subscription, not the customer: a customer's
             * `legacy_shopify_gid` is written by CustomerMapper for anyone imported from
             * Shopify at all — including someone who merely logged into the personal area
             * by SMS and never had a legacy subscription in their life. Keying on it put a
             * checkout-born test customer in this queue twice (26 and 27 Aug).
             *
             * A subscription imported from the legacy note carries `{gid}#legacy-note`;
             * one born at checkout carries an original_order_id and no legacy gid. That
             * distinction is the whole question "was Cardcom ever billing this person".
             */
            ->whereHas('subscriptions', fn (Builder $q) => $q->whereNotNull('legacy_shopify_gid'))
            ->whereHas('paymentMethods', fn (Builder $q) => $q
                ->where('source', 'card_update')
                ->where('is_active', true))
            ->with(['paymentMethods' => fn ($q) => $q->where('is_active', true)]);
    }

    public static function canView(): bool
    {
        // An empty queue is silence, not an empty box — the widget only exists when someone
        // is actually being double-billed.
        return self::pendingQuery()->exists();
    }

    /** @return Collection<int, Customer> */
    public function getPending(): Collection
    {
        return self::pendingQuery()->orderBy('cardcom_removed_at')->orderBy('id')->get();
    }

    public function confirm(int $customerId): void
    {
        $customer = self::pendingQuery()->whereKey($customerId)->first();

        if ($customer === null) {
            return;   // already confirmed elsewhere (another admin, another tab) — nothing to do
        }

        $customer->forceFill([
            'cardcom_removed_at' => now(),
            'cardcom_removed_by' => auth()->id(),
        ])->save();

        // Named and permanent: "who says the iCount charge is gone" must always have an
        // answer, because the failure this queue exists for is silent double billing.
        SystemLog::info('admin', 'iCount recurring charge confirmed removed', [
            'admin_id' => auth()->id(),
        ], ['customer_id' => $customer->id]);

        Timeline::record(Timeline::KIND_NOTE, [
            'action' => 'cardcom_charge_removed',
        ], null, $customer->id, Timeline::admin((int) auth()->id()));

        Notification::make()->title(__('dashboard.cardcom_confirmed'))->success()->send();
    }
}
