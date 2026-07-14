<?php

namespace App\Filament\Resources\Subscriptions\Schemas;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Dog;
use App\Models\PaymentMethod;
use App\Models\Subscription;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Services\Recommendation\DogFoodRecommender;
use App\Modules\MillsSubscriptions\Services\Shopify\DraftOrderService;
use App\Modules\MillsSubscriptions\Services\Shopify\OrderHistoryService;
use App\Modules\MillsSubscriptions\Support\SubscriptionPricing;
use App\Modules\MillsSubscriptions\Support\VariantResolver;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * The full subscription view: the subscriber, the plan, each dog WITH THE PRODUCTS IT IS
 * BILLED FOR (and their pictures), the upcoming order and what it will cost, the real
 * Shopify order history, and the money ledger.
 *
 * The product and order lists are rendered through Blade views rather than Filament
 * entries on purpose. Inside a RepeatableEntry, an array item is injected as the child
 * schema's *constant state*, so a closure's `$record` is still the PARENT record — which
 * is exactly how every order in the history ended up linking to /orders/1 (the id of the
 * subscription) and how the line items rendered empty. Building the link and the lines in
 * the service, and rendering them in a view, removes that whole class of bug.
 */
class SubscriptionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            /*
             * Two columns, Recharge-style: what is IN the subscription on the left (products,
             * the next order, the order history — the things you read top to bottom), and the
             * facts about it on the right (who, what plan, what money), where they stay in
             * view while you scroll the left.
             */
            Grid::make(3)->schema([
                Group::make()->columnSpan(2)->schema(self::mainColumn()),
                Group::make()->columnSpan(1)->schema(self::sideColumn()),
            ]),
        ]);
    }

    /** @return list<mixed> */
    private static function sideColumn(): array
    {
        return [
            Section::make(__('subscriptions.owner_details'))
                ->columns(1)
                ->schema([
                    TextEntry::make('customer_name')
                        ->label(__('subscriptions.name'))
                        ->state(fn (Subscription $record) => $record->customer?->fullName() ?? '—'),
                    TextEntry::make('customer.email')->label(__('subscriptions.email'))->copyable(),
                    TextEntry::make('customer.phone')->label(__('subscriptions.phone'))->placeholder('—'),
                    TextEntry::make('customer.shopify_customer_id')->label(__('subscriptions.shopify_id'))->placeholder('—'),
                    TextEntry::make('address')
                        ->label(__('subscriptions.address'))
                        ->columnSpanFull()
                        ->state(fn (Subscription $record) => trim(implode(', ', array_filter([
                            $record->customer?->address1,
                            $record->customer?->address2,
                            $record->customer?->city,
                            $record->customer?->zip,
                            $record->customer?->country,
                        ]))) ?: '—'),
                ]),

            Section::make(__('subscriptions.subscription_details'))
                ->columns(2)
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
                    TextEntry::make('discount_percent')
                        ->label(__('subscriptions.discount_percent'))
                        ->suffix('%')
                        ->placeholder('0'),
                    TextEntry::make('next_charge_at')
                        ->label(__('subscriptions.next_charge'))
                        ->dateTime('Y-m-d')
                        ->placeholder('—'),
                    TextEntry::make('next_charge_amount')
                        ->label(__('subscriptions.next_charge_amount'))
                        ->money('ILS')
                        ->weight('bold')
                        ->placeholder(__('subscriptions.amount_unknown'))
                        ->helperText(fn (Subscription $record) => self::amountHint($record)),
                ]),

            /*
             * The card that will actually be charged.
             *
             * `buyer_key` is never touched here — it is encrypted and $hidden on the model,
             * and the masked number is the only part of a card fit to put on a screen. A
             * subscription with no card is stated loudly rather than left to be inferred from
             * a badge somewhere else: it means nobody is being billed.
             */
            Section::make(__('subscriptions.payment_method'))
                ->columns(1)
                ->headerActions([
                    Action::make('updateCardInline')
                        ->label(__('subscriptions.action_update_card'))
                        ->icon(Heroicon::OutlinedCreditCard)
                        ->color('gray')
                        // Deep-links to the page header action — one definition of the modal.
                        ->url(fn (Subscription $record) => SubscriptionResource::getUrl('view', [
                            'record' => $record,
                            'action' => 'updateCard',
                        ])),
                ])
                ->schema([
                    TextEntry::make('card_warning')
                        ->hiddenLabel()
                        ->badge()
                        ->color('danger')
                        ->state(__('subscriptions.card_update_required'))
                        ->visible(fn (Subscription $record) => self::card($record) === null
                            || $record->payment_state === PaymentState::NEEDS_CARD_UPDATE),

                    TextEntry::make('card_masked')
                        ->label(__('subscriptions.card'))
                        ->state(fn (Subscription $record) => self::card($record)?->masked_card)
                        ->placeholder(__('subscriptions.no_card_on_file')),

                    TextEntry::make('card_captured_at')
                        ->label(__('subscriptions.card_captured_at'))
                        ->state(fn (Subscription $record) => self::card($record)?->captured_at)
                        ->dateTime('Y-m-d H:i')
                        ->placeholder('—'),
                ]),
        ];
    }

    private static function card(Subscription $subscription): ?PaymentMethod
    {
        return $subscription->customer?->activePaymentMethod();
    }

    /** @return list<mixed> */
    private static function mainColumn(): array
    {
        return [
            // The upcoming order: what ships next, and the amount PayMe will be asked for.
            Section::make(__('subscriptions.upcoming_order'))
                ->columns(1)
                // The edit lives where the thing being edited is, not only in the page header.
                ->headerActions([
                    Action::make('editUpcomingOrderInline')
                        ->label(__('subscriptions.edit'))
                        ->icon(Heroicon::OutlinedPencilSquare)
                        ->color('gray')
                        ->url(fn (Subscription $record) => SubscriptionResource::getUrl('view', [
                            'record' => $record,
                            'action' => 'editUpcomingOrder',
                        ])),
                ])
                ->schema([
                    TextEntry::make('line_items_overridden_at')
                        ->hiddenLabel()
                        ->badge()
                        ->color('warning')
                        ->state(__('subscriptions.edited_by_hand'))
                        // A hand-edited order must never look like a normal one — otherwise the
                        // next person to read this screen is misled about what will ship.
                        ->visible(fn (Subscription $record) => ! empty($record->line_items_override)),

                    ViewEntry::make('upcoming_order')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->view('filament.infolists.upcoming-order')
                        ->state(fn (Subscription $record) => self::upcomingOrder($record)),
                ]),

            // Each dog, its computed requirement, and the products it is billed for.
            Section::make(__('subscriptions.dogs').' — '.__('subscriptions.products'))
                ->headerActions([
                    Action::make('editDogs')
                        ->label(__('subscriptions.edit'))
                        ->icon(Heroicon::OutlinedPencilSquare)
                        ->color('gray')
                        // Changing a dog's food rebuilds the upcoming order automatically
                        // (DogObserver) — the screen, the charge and the box stay in step.
                        ->url(fn (Subscription $record) => SubscriptionResource::getUrl('edit', ['record' => $record])),
                ])
                ->schema([
                    RepeatableEntry::make('dogs')
                        ->hiddenLabel()
                        ->columns(4)
                        ->schema([
                            TextEntry::make('name')->label(__('subscriptions.name')),
                            TextEntry::make('weight')->label('kg')->placeholder('—'),
                            TextEntry::make('age')->label(__('subscriptions.age'))->placeholder('—'),
                            TextEntry::make('allergies')->label(__('subscriptions.allergies'))->placeholder('—'),

                            TextEntry::make('requirement')
                                ->label(__('subscriptions.daily_requirement'))
                                ->columnSpanFull()
                                ->state(fn (Dog $record) => self::requirement($record))
                                ->color('gray'),

                            ViewEntry::make('subscription_products')
                                ->label(__('subscriptions.products'))
                                ->columnSpanFull()
                                ->view('filament.infolists.product-lines')
                                ->state(fn (Dog $record) => VariantResolver::lines($record->selected_variants)),

                            ViewEntry::make('addon_products')
                                ->label(__('subscriptions.addons'))
                                ->columnSpanFull()
                                ->view('filament.infolists.product-lines')
                                ->state(fn (Dog $record) => VariantResolver::lines($record->addons_products))
                                ->visible(fn (Dog $record) => VariantResolver::lines($record->addons_products) !== []),
                        ]),
                ]),

            // The customer's REAL Shopify orders, each linking to ITS OWN order.
            Section::make(__('subscriptions.order_history'))
                ->schema([
                    ViewEntry::make('shopify_orders')
                        ->hiddenLabel()
                        ->view('filament.infolists.order-history')
                        ->state(fn (Subscription $record) => $record->customer
                            ? app(OrderHistoryService::class)->forCustomer($record->customer)
                            : []),
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
                                ->url(fn ($state) => OrderHistoryService::adminUrl('orders', (string) $state))
                                ->openUrlInNewTab()
                                ->color(fn ($state) => $state ? 'primary' : 'gray'),
                        ]),
                ]),
        ];
    }

    /**
     * The draft order, if one exists. NEVER creates it as a side effect of looking at the
     * page — a read must not write to Shopify. Creating it is an explicit action.
     *
     * @return array<string, mixed>
     */
    private static function upcomingOrder(Subscription $subscription): array
    {
        if (empty($subscription->draft_order_id)) {
            return [];
        }

        try {
            return app(DraftOrderService::class)->get($subscription);
        } catch (Throwable) {
            return [];   // Shopify unreachable — the screen still renders.
        }
    }

    /** Show the products-only subtotal beside the real total, so shipping/tax is visible. */
    private static function amountHint(Subscription $subscription): ?string
    {
        $subtotal = SubscriptionPricing::productsSubtotal($subscription);

        if ($subtotal <= 0) {
            return null;
        }

        return __('subscriptions.products_subtotal', ['amount' => '₪'.number_format($subtotal, 2)]);
    }

    /** "needs ~149 g/day (551 kcal)" — so a mismatch with the chosen product is visible. */
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
}
