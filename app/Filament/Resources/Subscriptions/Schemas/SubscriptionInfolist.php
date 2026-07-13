<?php

namespace App\Filament\Resources\Subscriptions\Schemas;

use App\Models\Dog;
use App\Models\ShopifyConnection;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Services\Recommendation\DogFoodRecommender;
use App\Modules\MillsSubscriptions\Services\Shopify\OrderHistoryService;
use App\Modules\MillsSubscriptions\Support\VariantResolver;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The full subscription view — the subscriber, the plan, EACH DOG WITH THE PRODUCTS
 * IT ACTUALLY GETS, the real Shopify order history, the upcoming order, and the
 * billing ledger.
 *
 * The products used to be invisible here: the only products entry read
 * `meta.line_items`, which nothing in the system ever writes. It now reads the real
 * source — the dog's chosen variants — resolved against the local product cache.
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
                ->columns(4)
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
                    TextEntry::make('next_charge_at')->label(__('subscriptions.next_charge'))->dateTime('Y-m-d')->placeholder('—'),
                ]),

            // Each dog, and the products that dog is actually billed for.
            Section::make(__('subscriptions.dogs').' — '.__('subscriptions.products'))
                ->schema([
                    RepeatableEntry::make('dogs')
                        ->hiddenLabel()
                        ->columns(4)
                        ->schema([
                            TextEntry::make('name')->label(__('subscriptions.name')),
                            TextEntry::make('weight')->label('kg')->placeholder('—'),
                            TextEntry::make('age')->label(__('subscriptions.age'))->placeholder('—'),
                            TextEntry::make('allergies')->label(__('subscriptions.allergies'))->placeholder('—'),

                            // What the engine says this dog needs — so an admin can see
                            // at a glance whether the chosen product still fits.
                            TextEntry::make('requirement')
                                ->label(__('subscriptions.daily_requirement'))
                                ->columnSpanFull()
                                ->state(fn (Dog $record) => self::requirement($record))
                                ->color('gray'),

                            TextEntry::make('subscription_products')
                                ->label(__('subscriptions.products'))
                                ->columnSpanFull()
                                ->state(fn (Dog $record) => VariantResolver::labels($record->selected_variants))
                                ->listWithLineBreaks()
                                ->bulleted()
                                ->placeholder(__('subscriptions.no_products')),

                            TextEntry::make('addon_products')
                                ->label(__('subscriptions.addons'))
                                ->columnSpanFull()
                                ->state(fn (Dog $record) => VariantResolver::labels($record->addons_products))
                                ->listWithLineBreaks()
                                ->bulleted()
                                ->placeholder('—'),
                        ]),
                ]),

            // The next order that will go out (the Shopify draft).
            Section::make(__('subscriptions.upcoming_order'))
                ->columns(2)
                ->schema([
                    TextEntry::make('next_charge_at')
                        ->label(__('subscriptions.next_charge'))
                        ->dateTime('Y-m-d')
                        ->placeholder('—'),
                    TextEntry::make('draft_order_id')
                        ->label(__('subscriptions.draft_order'))
                        ->placeholder(__('subscriptions.no_draft_yet'))
                        ->url(fn ($record) => self::orderUrl($record->draft_order_id, 'draft_orders'))
                        ->openUrlInNewTab()
                        ->color(fn ($record) => self::orderUrl($record->draft_order_id, 'draft_orders') ? 'primary' : 'gray'),
                ]),

            // The customer's REAL Shopify orders — read live (cached 5 min).
            Section::make(__('subscriptions.order_history'))
                ->schema([
                    RepeatableEntry::make('shopify_orders')
                        ->hiddenLabel()
                        ->columns(5)
                        ->state(fn ($record) => $record->customer
                            ? app(OrderHistoryService::class)->forCustomer($record->customer)
                            : [])
                        ->schema([
                            TextEntry::make('name')
                                ->label(__('subscriptions.order'))
                                ->url(fn ($state, $record) => self::orderUrl((string) ($record['id'] ?? '')))
                                ->openUrlInNewTab()
                                ->color('primary'),
                            TextEntry::make('created_at')->label(__('subscriptions.date'))->dateTime('Y-m-d'),
                            TextEntry::make('financial_status')->label(__('subscriptions.status'))->badge()
                                ->color(fn ($state) => $state === 'PAID' ? 'success' : 'warning'),
                            TextEntry::make('total')->label(__('subscriptions.total'))->money('ILS'),
                            TextEntry::make('items')
                                ->label(__('subscriptions.products'))
                                ->state(fn ($record) => array_map(
                                    fn (array $item) => trim(($item['title'] ?? '—').' × '.($item['quantity'] ?? 1)
                                        .($item['sku'] ? '  ('.$item['sku'].')' : '')),
                                    $record['line_items'] ?? [],
                                ))
                                ->listWithLineBreaks()
                                ->bulleted()
                                ->columnSpanFull(),
                        ]),
                ]),

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

    /** "needs ~149 g/day (551 kcal)" — or why we can't say. */
    private static function requirement(Dog $dog): string
    {
        $recommender = app(DogFoodRecommender::class);

        if (! $recommender->canRecommend($dog)) {
            return __('subscriptions.no_recommendation');
        }

        $result = $recommender->recommend($dog);
        $best = $result['products'][0] ?? null;

        if ($best === null) {
            return __('subscriptions.calories_only', ['calories' => $result['calories']]);
        }

        return __('subscriptions.requirement', [
            'grams' => $best['benchmark'],
            'calories' => $result['calories'],
        ]);
    }

    private static function orderUrl(?string $id, string $resource = 'orders'): ?string
    {
        if (empty($id) || preg_match('/(\d+)/', (string) $id, $m) !== 1) {
            return null;
        }

        return 'https://admin.shopify.com/store/'.self::storeHandle()."/{$resource}/{$m[1]}";
    }

    private static function storeHandle(): string
    {
        $domain = (string) (ShopifyConnection::current()?->shop_domain ?: config('shopify.shop_domain'));

        return str_replace('.myshopify.com', '', $domain) ?: 'millsforpets';
    }
}
