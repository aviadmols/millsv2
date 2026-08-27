<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Jobs\ImportLegacyCustomersJob;
use App\Modules\MillsSubscriptions\Services\LegacyBulkImporter;
use App\Modules\MillsSubscriptions\Services\LegacyCustomerImporter;
use App\Modules\MillsSubscriptions\Services\Shopify\ShopifyAdminClient;
use App\Modules\MillsSubscriptions\Services\Shopify\ShopifyCustomerService;
use App\Modules\MillsSubscriptions\Support\LegacyNoteParser;
use App\Support\PhoneNumber;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
            $this->pushByPhoneAction(),
            $this->importFromShopifyAction(),
            $this->bulkImportAction(),
            CreateAction::make(),
        ];
    }

    /**
     * Bring the whole Cardcom-era list across — paste the emails, press import.
     *
     * The one-at-a-time import above is the right tool for one person; it is not a way to
     * move 1,172. This takes the list as it comes out of a spreadsheet (one address per
     * line, header row and blanks ignored), CHECKS it first without writing anything, and
     * then hands the work to the worker — 1,172 emails is 1,172 Shopify lookups, which has
     * no business happening inside a browser request.
     *
     * Everyone imported lands behind the card-update wall, which is the whole point: we
     * hold no PayMe token for any of them, so nothing may be charged until they enter a
     * card. Running it twice is free — a customer already here is left alone.
     */
    private function bulkImportAction(): Action
    {
        return Action::make('bulkImport')
            ->label(__('customers.action_bulk_import'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->modalHeading(__('customers.bulk_import_heading'))
            ->modalDescription(__('customers.bulk_import_help'))
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitActionLabel(__('customers.bulk_import_submit'))
            ->schema([
                Textarea::make('emails')
                    ->label(__('customers.bulk_import_field'))
                    ->rows(12)
                    ->required()
                    ->live(debounce: 800)
                    ->helperText(fn (Get $get) => __('customers.bulk_import_counted', [
                        'count' => count(LegacyBulkImporter::emailsIn((string) $get('emails'))),
                    ])),

                Toggle::make('dry_run')
                    ->label(__('customers.bulk_import_dry_run'))
                    ->helperText(__('customers.bulk_import_dry_run_help'))
                    ->default(true),

                TextInput::make('limit')
                    ->label(__('customers.bulk_import_limit'))
                    ->helperText(__('customers.bulk_import_limit_help'))
                    ->numeric()
                    ->minValue(0)
                    ->default(3),
            ])
            ->action(function (array $data): void {
                $emails = LegacyBulkImporter::emailsIn((string) ($data['emails'] ?? ''));
                $limit = max(0, (int) ($data['limit'] ?? 0));

                if ($limit > 0) {
                    $emails = array_slice($emails, 0, $limit);
                }

                if ($emails === []) {
                    Notification::make()->title(__('customers.bulk_import_none'))->warning()->send();

                    return;
                }

                /*
                 * A dry run answers "is this list real" — how many of these people Shopify
                 * actually knows — and it answers NOW, on screen, because a check you have
                 * to go and look up somewhere else is a check nobody runs.
                 */
                if ($data['dry_run'] ?? true) {
                    $tally = app(LegacyBulkImporter::class)->run($emails, dryRun: true);

                    Notification::make()
                        ->title(__('customers.bulk_import_dry_result', [
                            'found' => $tally['imported'],
                            'missing' => $tally['not_in_shopify'],
                        ]))
                        ->success()
                        ->persistent()
                        ->send();

                    return;
                }

                ImportLegacyCustomersJob::dispatch($emails);

                Notification::make()
                    ->title(__('customers.bulk_import_queued', ['count' => count($emails)]))
                    ->body(__('customers.bulk_import_queued_help'))
                    ->success()
                    ->persistent()
                    ->send();
            });
    }

    /**
     * Push a customer into the system from a phone number alone.
     *
     * A phone number is what support has when someone calls — not a Shopify customer id, and
     * usually not the email they signed up with either. This finds them in Shopify, brings
     * them across with whatever subscription is on their note, and leaves them flagged as
     * needing a card, which is exactly the state the migration expects.
     *
     * The same import the customer's own SMS login performs, so a customer pushed here and a
     * customer who logged in themselves end up identical — no second code path to drift.
     */
    private function pushByPhoneAction(): Action
    {
        return Action::make('pushByPhone')
            ->label(__('customers.action_push_by_phone'))
            ->icon(Heroicon::OutlinedDevicePhoneMobile)
            ->color('primary')
            ->visible(fn () => app(ShopifyAdminClient::class)->isConnected())
            ->modalHeading(__('customers.action_push_by_phone'))
            ->modalDescription(__('customers.push_help'))
            ->modalSubmitActionLabel(__('customers.action_push_submit'))
            ->schema([
                TextInput::make('phone')
                    ->label(__('customers.phone'))
                    ->tel()
                    ->required()
                    ->placeholder('050-0000000')
                    ->helperText(__('customers.phone_help')),
            ])
            ->action(function (array $data) {
                $phone = trim((string) $data['phone']);

                try {
                    $matches = app(ShopifyCustomerService::class)->searchByPhone($phone);
                } catch (Throwable $e) {
                    Notification::make()->title(__('customers.push_failed'))->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }

                if ($matches === []) {
                    Notification::make()
                        ->title(__('customers.push_not_found'))
                        ->body(__('customers.push_not_found_help', ['phone' => PhoneNumber::local($phone) ?? $phone]))
                        ->warning()
                        ->send();

                    return;
                }

                // One number can hold several accounts; every one of them is brought in, so
                // support is never left wondering which of the three they got.
                $imported = 0;
                $lastCustomerId = null;

                foreach ($matches as $match) {
                    $result = app(LegacyCustomerImporter::class)->import((string) $match['id'], (int) auth()->id());

                    if ($result['customer_id'] !== null) {
                        $imported++;
                        $lastCustomerId = $result['customer_id'];
                    }
                }

                if ($imported === 0) {
                    Notification::make()->title(__('customers.push_failed'))->danger()->send();

                    return;
                }

                Notification::make()
                    ->title(__('customers.push_done', ['count' => $imported]))
                    ->body(__('customers.push_done_help'))
                    ->success()
                    ->send();

                if ($imported === 1 && $lastCustomerId !== null) {
                    $this->redirect(CustomerResource::getUrl('edit', ['record' => $lastCustomerId]));
                }
            });
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
