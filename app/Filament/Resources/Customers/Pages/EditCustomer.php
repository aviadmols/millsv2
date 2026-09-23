<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Customer;
use App\Modules\MillsSubscriptions\Services\LegacyCustomerImporter;
use App\Modules\MillsSubscriptions\Services\Shopify\ShopifyAdminClient;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Throwable;

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->importSubscriptionAction(),
            DeleteAction::make(),
        ];
    }

    /**
     * Pull this one customer's old subscription across, from their own page.
     *
     * A customer can be here without a subscription for reasons that have nothing to do with
     * them: they arrived on an order webhook, or the old system was still mid-signup when
     * somebody imported them and the note held no active subscription yet (customer
     * 7397221925168, 2026-09-23). Until now the only way back was the customer LIST — search
     * the phone again and press push — which nobody thinks to do while looking at the
     * customer who is missing a subscription.
     *
     * Offered only when it can actually do something: Shopify connected, the customer linked
     * to a Shopify account, and no subscription here yet — importing over an existing one is
     * how a customer ends up billed twice.
     */
    private function importSubscriptionAction(): Action
    {
        return Action::make('importSubscription')
            ->label(__('customers.action_import_subscription'))
            ->icon(Heroicon::OutlinedCloudArrowDown)
            ->color('primary')
            ->visible(function (): bool {
                $customer = $this->getRecord();

                return $customer instanceof Customer
                    && filled($customer->shopify_customer_id)
                    && ! $customer->subscriptions()->exists()
                    && app(ShopifyAdminClient::class)->isConnected();
            })
            ->requiresConfirmation()
            ->modalHeading(__('customers.action_import_subscription'))
            ->modalDescription(__('customers.import_subscription_help'))
            ->modalSubmitActionLabel(__('customers.action_import_submit'))
            ->action(function (): void {
                /** @var Customer $customer */
                $customer = $this->getRecord();

                try {
                    $result = app(LegacyCustomerImporter::class)
                        ->import((string) $customer->shopify_customer_id, (int) auth()->id());
                } catch (Throwable $e) {
                    Notification::make()
                        ->title(__('customers.import_failed'))
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                $imported = $result['status'] === LegacyCustomerImporter::STATUS_IMPORTED;

                Notification::make()
                    ->title(__('customers.import_'.$result['status']))
                    // Why it did not come across, in the note's own words — the status the
                    // old system wrote is the part an admin can act on.
                    ->body($result['reason'] ?? null)
                    ->{$imported ? 'success' : 'warning'}()
                    ->persistent()
                    ->send();

                if ($imported && $result['subscription_id'] !== null) {
                    $this->redirect(SubscriptionResource::getUrl('view', ['record' => $result['subscription_id']]));
                }
            });
    }
}
