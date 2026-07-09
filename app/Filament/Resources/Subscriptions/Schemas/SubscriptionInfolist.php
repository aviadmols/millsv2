<?php

namespace App\Filament\Resources\Subscriptions\Schemas;

use App\Models\ShopifyConnection;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The full subscription view — subscriber details + subscription + the created
 * order (products, links to Shopify) + dogs + billing history, modeled on the
 * RECHARGE / v1 subscription page so nothing is lost.
 */
class SubscriptionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('subscriptions.owner_details'))
                ->columns(2)
                ->schema([
                    TextEntry::make('customer_name')
                        ->label(__('subscriptions.name'))
                        ->state(fn ($record) => $record->customer?->fullName() ?? '—'),
                    TextEntry::make('customer.email')->label(__('subscriptions.email'))->copyable(),
                    TextEntry::make('customer.phone')->label(__('subscriptions.phone'))->placeholder('—'),
                    TextEntry::make('customer.shopify_customer_id')->label(__('subscriptions.shopify_id'))->placeholder('—'),
                    TextEntry::make('address')
                        ->label(__('subscriptions.address'))
                        ->columnSpanFull()
                        ->state(fn ($record) => trim(implode(', ', array_filter([
                            $record->customer?->address1,
                            $record->customer?->address2,
                            $record->customer?->city,
                            $record->customer?->zip,
                            $record->customer?->country,
                        ]))) ?: '—'),
                ]),

            Section::make(__('subscriptions.subscription_details'))
                ->columns(3)
                ->schema([
                    TextEntry::make('status')
                        ->label(__('subscriptions.status'))
                        ->badge()
                        ->formatStateUsing(fn (SubscriptionStatus $state) => __('subscriptions.status_'.$state->value))
                        ->color(fn (SubscriptionStatus $state) => match ($state) {
                            SubscriptionStatus::ACTIVE => 'success',
                            SubscriptionStatus::PENDING, SubscriptionStatus::PAUSED => 'warning',
                            SubscriptionStatus::PAST_DUE => 'danger',
                            SubscriptionStatus::CANCELLED => 'gray',
                        }),
                    TextEntry::make('payment_state')
                        ->label(__('subscriptions.payment'))
                        ->badge()
                        ->formatStateUsing(fn (PaymentState $state) => __('subscriptions.pay_'.$state->value))
                        ->color(fn (PaymentState $state) => $state === PaymentState::PAYME ? 'success' : 'warning'),
                    TextEntry::make('frequency_months')
                        ->label(__('subscriptions.frequency'))
                        ->formatStateUsing(fn (int $state) => $state === 2 ? __('subscriptions.every_2_months') : __('subscriptions.monthly')),
                    TextEntry::make('next_charge_at')->label(__('subscriptions.next_charge'))->dateTime('Y-m-d'),
                ]),

            // The order this subscription is tied to — original order, the upcoming
            // (draft) order preview, and the products that make up each order.
            Section::make(__('subscriptions.order_details'))
                ->columns(2)
                ->schema([
                    TextEntry::make('original_order_id')
                        ->label(__('subscriptions.original_order'))
                        ->placeholder('—')
                        ->url(fn ($record) => self::orderUrl($record->original_order_id))
                        ->openUrlInNewTab()
                        ->color(fn ($record) => self::orderUrl($record->original_order_id) ? 'primary' : null),
                    TextEntry::make('draft_order_id')
                        ->label(__('subscriptions.upcoming_order'))
                        ->placeholder('—')
                        ->url(fn ($record) => self::orderUrl($record->draft_order_id, 'draft_orders'))
                        ->openUrlInNewTab()
                        ->color(fn ($record) => self::orderUrl($record->draft_order_id, 'draft_orders') ? 'primary' : null),

                    TextEntry::make('no_products')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->state(__('subscriptions.no_products'))
                        ->color('gray')
                        ->visible(fn ($record) => empty($record->line_items)),
                    RepeatableEntry::make('line_items')
                        ->label(__('subscriptions.products'))
                        ->columnSpanFull()
                        ->columns(4)
                        ->visible(fn ($record) => ! empty($record->line_items))
                        ->schema([
                            TextEntry::make('title')->label(__('subscriptions.product'))->placeholder('—'),
                            TextEntry::make('sku')->label(__('subscriptions.sku'))->placeholder('—'),
                            TextEntry::make('quantity')->label(__('subscriptions.quantity'))->placeholder('1'),
                            TextEntry::make('price')->label(__('subscriptions.price'))->money('ILS')->placeholder('—'),
                        ]),
                ]),

            Section::make(__('subscriptions.dogs'))
                ->schema([
                    RepeatableEntry::make('dogs')
                        ->hiddenLabel()
                        ->columns(4)
                        ->schema([
                            TextEntry::make('name')->label(__('subscriptions.name')),
                            TextEntry::make('weight')->label('kg')->placeholder('—'),
                            TextEntry::make('age')->label('age')->placeholder('—'),
                            TextEntry::make('allergies')->placeholder('—'),
                        ]),
                ]),

            // Every charge is an order — date, amount, result, transaction, and a
            // direct link to the order it created in Shopify (once billing runs).
            Section::make(__('subscriptions.billing_history'))
                ->schema([
                    RepeatableEntry::make('ledgerEntries')
                        ->hiddenLabel()
                        ->columns(5)
                        ->schema([
                            TextEntry::make('executed_at')->label(__('subscriptions.date'))->dateTime('Y-m-d H:i')->placeholder('—'),
                            TextEntry::make('context')
                                ->label(__('subscriptions.context'))
                                ->badge()
                                ->formatStateUsing(function ($state) {
                                    $key = 'subscriptions.ctx_'.$state;

                                    return __($key) === $key ? (string) $state : __($key);
                                })
                                ->color('gray'),
                            TextEntry::make('amount')->label(__('subscriptions.price'))->money('ILS')->placeholder('—'),
                            TextEntry::make('status')->label(__('subscriptions.status'))->badge()
                                ->color(fn ($state) => in_array((string) ($state->value ?? $state), ['succeeded'], true) ? 'success' : 'danger'),
                            TextEntry::make('shopify_order_id')
                                ->label(__('subscriptions.created_order'))
                                ->placeholder('—')
                                ->formatStateUsing(fn ($state) => $state ? __('subscriptions.view_in_shopify') : '—')
                                ->url(fn ($record) => self::orderUrl($record->shopify_order_id))
                                ->openUrlInNewTab()
                                ->color(fn ($record) => self::orderUrl($record->shopify_order_id) ? 'primary' : 'gray'),
                        ]),
                ]),
        ]);
    }

    /**
     * Build a Shopify admin URL for an order id (GID or numeric). Returns null when
     * the id is empty so the entry renders as plain text, not a dead link.
     */
    private static function orderUrl(?string $id, string $resource = 'orders'): ?string
    {
        if (empty($id) || preg_match('/(\d+)/', (string) $id, $m) !== 1) {
            return null;
        }

        return "https://admin.shopify.com/store/".self::storeHandle()."/{$resource}/{$m[1]}";
    }

    private static function storeHandle(): string
    {
        $domain = (string) (ShopifyConnection::current()?->shop_domain ?: config('shopify.shop_domain'));

        return str_replace('.myshopify.com', '', $domain) ?: 'millsforpets';
    }
}
