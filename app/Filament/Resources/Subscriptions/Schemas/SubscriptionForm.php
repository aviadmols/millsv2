<?php

namespace App\Filament\Resources\Subscriptions\Schemas;

use App\Models\ProductVariant;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The full subscription editor — everything the v1 admin could change: the owner,
 * the plan, the order links, and (the part that matters day to day) each dog's
 * PRODUCTS: its recurring flavour variants and its add-ons, picked from the local
 * product cache that mills:sync-products keeps in step with the store.
 */
class SubscriptionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('subscriptions.owner_details'))
                    ->columns(2)
                    ->schema([
                        Select::make('customer_id')
                            ->label(__('subscriptions.customer'))
                            ->relationship('customer', 'email')
                            ->getOptionLabelFromRecordUsing(fn ($record) => trim($record->fullName().' — '.$record->email))
                            ->searchable(['first_name', 'last_name', 'email'])
                            ->preload()
                            ->required(),
                    ]),

                Section::make(__('subscriptions.subscription_details'))
                    ->columns(2)
                    ->schema([
                        Select::make('status')
                            ->label(__('subscriptions.status'))
                            ->options(fn () => collect(SubscriptionStatus::cases())
                                ->mapWithKeys(fn ($c) => [$c->value => __('subscriptions.status_'.$c->value)])
                                ->all())
                            ->required(),
                        Select::make('payment_state')
                            ->label(__('subscriptions.payment'))
                            ->options(fn () => collect(PaymentState::cases())
                                ->mapWithKeys(fn ($c) => [$c->value => __('subscriptions.pay_'.$c->value)])
                                ->all())
                            ->required(),
                        Select::make('frequency_months')
                            ->label(__('subscriptions.frequency'))
                            ->options([
                                1 => __('subscriptions.monthly'),
                                2 => __('subscriptions.every_2_months'),
                            ])
                            ->default(1)
                            ->required(),
                        DatePicker::make('next_charge_at')
                            ->label(__('subscriptions.next_charge'))
                            ->native(false),
                    ]),

                Section::make(__('subscriptions.order_details'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('original_order_id')->label(__('subscriptions.original_order')),
                        TextInput::make('draft_order_id')->label(__('subscriptions.upcoming_order')),
                    ]),

                // Products live on the dog — this is where they are added and managed.
                Section::make(__('subscriptions.dogs').' — '.__('subscriptions.products'))
                    ->schema([
                        Repeater::make('dogs')
                            ->hiddenLabel()
                            ->relationship('dogs')
                            ->itemLabel(fn (array $state) => $state['name'] ?? __('subscriptions.dogs'))
                            ->collapsed()
                            ->columns(2)
                            ->schema([
                                TextInput::make('name')->label(__('subscriptions.name')),
                                TextInput::make('weight')->label('kg')->numeric(),

                                Select::make('selected_variants')
                                    ->label(__('subscriptions.products'))
                                    ->helperText(__('subscriptions.products_help'))
                                    ->multiple()
                                    ->searchable()
                                    ->options(fn () => self::variantOptions())
                                    ->columnSpanFull(),

                                Select::make('addons_products')
                                    ->label(__('subscriptions.addons'))
                                    ->multiple()
                                    ->searchable()
                                    ->options(fn () => self::variantOptions())
                                    ->columnSpanFull(),
                            ]),
                    ]),
            ]);
    }

    /**
     * Options come from the LOCAL product cache (never a live Shopify call), keyed
     * by the Shopify variant id — the same id the storefront and draft orders use.
     *
     * @return array<string, string>
     */
    private static function variantOptions(): array
    {
        return ProductVariant::query()
            ->with('product')
            ->orderBy('product_id')
            ->orderBy('position')
            ->get()
            ->mapWithKeys(function (ProductVariant $variant) {
                $label = trim(($variant->product?->title ?? '—').' · '.($variant->title ?? ''));
                if ($variant->sku) {
                    $label .= " ({$variant->sku})";
                }

                return [(string) $variant->shopify_variant_id => $label];
            })
            ->all();
    }
}
