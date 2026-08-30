<?php

namespace App\Filament\Resources\Subscriptions\Pages;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Jobs\RefreshUpcomingOrdersJob;
use App\Models\Subscription;
use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListSubscriptions extends ListRecords
{
    protected static string $resource = SubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->refreshUpcomingOrdersAction(),
            CreateAction::make(),
        ];
    }

    /**
     * Rebuild every upcoming order, and with it the amount that will actually be charged.
     *
     * `next_charge_amount` is a STORED copy of the upcoming Shopify draft's total, refreshed
     * only when something touches that subscription. So a change to how orders are priced —
     * dropping the 10% subscriber discount, a price change in the store — reaches nobody
     * until their draft is rebuilt: they keep being billed the old figure for another full
     * cycle, and the dashboard keeps showing it.
     *
     * This is the button that applies such a change to everyone at once, deliberately and
     * visibly. It only ever asks Shopify what the order costs now — it never invents a
     * price, and a subscription Shopify cannot price keeps its old amount rather than being
     * given a guess.
     */
    private function refreshUpcomingOrdersAction(): Action
    {
        return Action::make('refreshUpcomingOrders')
            ->label(__('subscriptions.action_refresh_upcoming'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('subscriptions.refresh_upcoming_heading'))
            // The confirmation names the number of customers whose charge may move. A sweep
            // over the whole book should never be one unlabelled click away.
            ->modalDescription(fn () => __('subscriptions.refresh_upcoming_help', [
                'count' => self::refreshableQuery()->count(),
            ]))
            ->modalSubmitActionLabel(__('subscriptions.refresh_upcoming_submit'))
            ->action(function (): void {
                $ids = self::refreshableQuery()->pluck('id')->all();

                if ($ids === []) {
                    Notification::make()->title(__('subscriptions.refresh_upcoming_none'))->warning()->send();

                    return;
                }

                // Chunked for the same reason the bulk import is: each refresh is a Shopify
                // round trip, and a job that outlives the queue's 90-second release window
                // is picked up again while still running and eventually stamped failed.
                foreach (array_chunk($ids, RefreshUpcomingOrdersJob::CHUNK_SIZE) as $chunk) {
                    RefreshUpcomingOrdersJob::dispatch($chunk);
                }

                SystemLog::info('billing', 'rebuild of every upcoming order queued', [
                    'subscriptions' => count($ids),
                ]);

                Notification::make()
                    ->title(__('subscriptions.refresh_upcoming_started', ['count' => count($ids)]))
                    ->success()
                    ->persistent()
                    ->send();
            });
    }

    /**
     * Only subscriptions that HAVE an upcoming order to rebuild.
     *
     * Cancelled ones are not going to be charged, and one with no draft yet has nothing to
     * refresh — its first draft is built when it becomes billable.
     */
    private static function refreshableQuery()
    {
        return Subscription::query()
            ->whereIn('status', [SubscriptionStatus::ACTIVE->value, SubscriptionStatus::PAUSED->value])
            ->whereNotNull('draft_order_id');
    }
}
