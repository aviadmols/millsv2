<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Modules\MillsSubscriptions\Services\LegacyCustomerImporter;
use App\Modules\MillsSubscriptions\Services\Shopify\ShopifyAdminClient;
use App\Modules\MillsSubscriptions\Services\Shopify\ShopifyCustomerService;
use App\Modules\MillsSubscriptions\Support\LegacyNoteParser;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Throwable;

class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->importFromShopifyAction(),
            CreateAction::make(),
        ];
    }

    /**
     * Add a customer who exists in the store but not here — and bring their old subscription
     * with them.
     *
     * The iCount population never made it into v2: the one-time import took the PayMe
     * customers and skipped everyone else, and their subscriptions have been sitting in the
     * Shopify customer note ever since. This is the way back in, one customer at a time.
     *
     * The preview is not decoration. Picking the wrong row here creates a live subscription
     * for a stranger, so what is about to be created is shown before it is.
     */
    private function importFromShopifyAction(): Action
    {
        return Action::make('importFromShopify')
            ->label(__('customers.action_import_from_shopify'))
            ->icon(Heroicon::OutlinedCloudArrowDown)
            ->color('gray')
            ->visible(fn () => app(ShopifyAdminClient::class)->isConnected())
            ->modalHeading(__('customers.action_import_from_shopify'))
            ->modalDescription(__('customers.import_help'))
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitActionLabel(__('customers.action_import_submit'))
            ->schema([
                Select::make('shopify_customer_id')
                    ->label(__('customers.shopify_customer'))
                    ->required()
                    ->searchable()
                    // One Shopify call per pause in typing, not one per keystroke.
                    ->searchDebounce(500)
                    ->searchPrompt(__('customers.search_prompt'))
                    ->getSearchResultsUsing(fn (string $search) => $this->searchShopify($search))
                    ->getOptionLabelUsing(fn (string $value) => $this->optionLabel(
                        app(ShopifyCustomerService::class)->find($value)
                    ))
                    ->live(),

                Placeholder::make('preview')
                    ->label(__('customers.import_preview'))
                    ->visible(fn (Get $get) => filled($get('shopify_customer_id')))
                    ->content(fn (Get $get) => view('filament.actions.legacy-import-preview', [
                        'preview' => app(LegacyCustomerImporter::class)->preview((string) $get('shopify_customer_id')),
                    ])),
            ])
            ->action(function (array $data) {
                try {
                    $result = app(LegacyCustomerImporter::class)->import(
                        (string) $data['shopify_customer_id'],
                        (int) auth()->id(),
                    );
                } catch (Throwable $e) {
                    Notification::make()
                        ->title(__('customers.import_failed'))
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                $this->announce($result);
            });
    }

    /** @param array<string, mixed> $result */
    private function announce(array $result): void
    {
        $status = (string) $result['status'];
        $imported = $status === LegacyCustomerImporter::STATUS_IMPORTED;

        Notification::make()
            ->title(__('customers.import_'.$status))
            ->{$imported ? 'success' : 'warning'}()
            ->send();

        if ($imported && $result['subscription_id'] !== null) {
            $this->redirect(SubscriptionResource::getUrl('view', ['record' => $result['subscription_id']]));
        }
    }

    /** @return array<string, string> */
    private function searchShopify(string $search): array
    {
        $results = app(ShopifyCustomerService::class)->search($search);

        $options = [];

        foreach ($results as $customer) {
            $options[(string) $customer['id']] = $this->optionLabel($customer);
        }

        return $options;
    }

    /** @param array<string, mixed> $customer */
    private function optionLabel(array $customer): string
    {
        if ($customer === []) {
            return '—';
        }

        $name = trim(($customer['first_name'] ?? '').' '.($customer['last_name'] ?? ''));

        $label = implode(' · ', array_filter([
            $name !== '' ? $name : null,
            $customer['email'] ?? null,
            $customer['phone'] ?? null,
        ]));

        // Say, right there in the dropdown, which of these people actually carry a
        // subscription worth importing — otherwise the admin is picking names blind.
        if (LegacyNoteParser::parseActiveNote((string) ($customer['note'] ?? '')) !== null) {
            $label .= ' — '.__('customers.has_legacy_subscription');
        }

        return $label !== '' ? $label : (string) $customer['id'];
    }
}
