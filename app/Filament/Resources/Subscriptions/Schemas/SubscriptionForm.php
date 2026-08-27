<?php

namespace App\Filament\Resources\Subscriptions\Schemas;

use App\Filament\Forms\AllergySelect;
use App\Models\Dog;
use App\Models\ProductVariant;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Services\Recommendation\DogFoodRecommender;
use App\Modules\MillsSubscriptions\Support\VariantResolver;
use Filament\Actions\Action as FormAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/**
 * The full subscription editor — owner, plan, order links, and each dog's PRODUCTS.
 *
 * The picker offers the WHOLE catalogue. An admin choosing products by hand knows things
 * the engine does not, and a list that hides the product they came for is an obstacle
 * rather than help. The one thing that does filter it is an allergy: a food the dog
 * reacts to is not a preference the engine may be overruled on.
 *
 * The engine's opinion is a button beside the picker — "suggest products that fit" opens
 * what it would choose, split into 30-day and 15-day packs, and adds whatever is ticked
 * to the selection. An opinion offered is not the same thing as an option withheld.
 */
class SubscriptionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            /*
             * ONE column of sections, full width.
             *
             * Filament defaults a resource form's schema to two columns, which laid every
             * Section out at half the screen with dead space beside it — the sections have
             * their own internal columns, so they need the whole width, not half of it.
             */
            ->columns(1)
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
                                ->mapWithKeys(fn ($c) => [$c->value => __('subscriptions.status_'.$c->value)])->all())
                            ->required(),
                        Select::make('payment_state')
                            ->label(__('subscriptions.payment'))
                            ->options(fn () => collect(PaymentState::cases())
                                ->mapWithKeys(fn ($c) => [$c->value => __('subscriptions.pay_'.$c->value)])->all())
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
                        TextInput::make('discount_percent')
                            ->label(__('subscriptions.discount_percent'))
                            ->helperText(__('subscriptions.discount_help'))
                            ->numeric()
                            ->suffix('%')
                            ->minValue(0)
                            ->maxValue(100)
                            ->default(10)
                            ->required(),
                    ]),

                Section::make(__('subscriptions.order_details'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('original_order_id')->label(__('subscriptions.original_order')),
                        TextInput::make('draft_order_id')->label(__('subscriptions.upcoming_order')),
                    ]),

                Section::make(__('subscriptions.dogs').' — '.__('subscriptions.products'))
                    ->description(__('subscriptions.picker_help'))
                    ->schema([
                        Repeater::make('dogs')
                            ->hiddenLabel()
                            ->relationship('dogs')
                            ->itemLabel(fn (array $state) => $state['name'] ?? __('subscriptions.dogs'))
                            ->collapsed()
                            ->columns(3)
                            ->schema([
                                TextInput::make('name')->label(__('subscriptions.name')),
                                TextInput::make('weight')->label('kg')->numeric()->live(debounce: 700),
                                TextInput::make('age')->label(__('subscriptions.age'))->numeric()->live(debounce: 700),

                                Select::make('activity')
                                    ->label(__('subscriptions.activity'))
                                    ->options([
                                        0 => __('subscriptions.activity_inactive'),
                                        1 => __('subscriptions.activity_active'),
                                        2 => __('subscriptions.activity_very_active'),
                                    ])
                                    ->live(),
                                Select::make('body')
                                    ->label(__('subscriptions.body'))
                                    ->options([
                                        0 => __('subscriptions.body_thin'),
                                        1 => __('subscriptions.body_normal'),
                                        2 => __('subscriptions.body_heavy'),
                                    ])
                                    ->live(),
                                Toggle::make('neutered')->label(__('subscriptions.neutered'))->live(),

                                AllergySelect::make()->columnSpan(3),

                                /*
                                 * The WHOLE catalogue, always — an admin picking products
                                 * by hand knows something the engine does not, and a list
                                 * that hides the product they came for is an obstacle, not
                                 * help. Allergies still filter it: those are safety.
                                 *
                                 * The engine's opinion is offered as an ACTION instead of
                                 * a restriction — press it and the fitting variants are
                                 * added to whatever is already chosen.
                                 */
                                Select::make('selected_variants')
                                    ->label(__('subscriptions.products'))
                                    ->helperText(fn (Get $get) => self::requirementHint($get))
                                    ->multiple()
                                    ->searchable()
                                    // The whole shop, not just the subscription flavours: an
                                    // admin adding a product by hand may be adding anything.
                                    // 500, because Filament shows only the first 50 by
                                    // default and a catalogue that stops mid-list reads as a
                                    // catalogue that does not carry the rest.
                                    ->optionsLimit(500)
                                    ->options(fn (Get $get) => self::allVariantOptions(self::dogFromForm($get)))
                                    ->columnSpanFull(),

                                Actions::make([self::suggestVariantsAction()])->columnSpanFull(),

                                Select::make('addons_products')
                                    ->label(__('subscriptions.addons'))
                                    ->multiple()
                                    ->searchable()
                                    // A free customer choice — never weight-filtered. Allergies
                                    // are the exception: those are safety, not preference.
                                    ->options(fn (Get $get) => self::allVariantOptions(self::dogFromForm($get)))
                                    ->columnSpanFull(),
                            ]),
                    ]),
            ]);
    }

    /**
     * "Show me what fits" — the engine's opinion, on request, in a list you choose from.
     *
     * Not a filter on the picker: an admin choosing by hand knows things the engine does
     * not, and a catalogue that hides the product they came for is an obstacle. Not a
     * one-press "add everything" either — that was the first attempt, and an icon that
     * silently changed the selection told nobody what it had decided.
     *
     * Split by pack size because that is the real question in front of the person: 30 a
     * month of one flavour, or 15 each of two. The same grams/day appears in both lists.
     */
    private static function suggestVariantsAction(): FormAction
    {
        return FormAction::make('suggestVariants')
            ->label(__('subscriptions.suggest_products'))
            ->icon(Heroicon::OutlinedSparkles)
            ->color('gray')
            ->modalHeading(__('subscriptions.suggest_products'))
            ->modalDescription(fn (Get $get) => self::requirementHint($get))
            ->modalSubmitActionLabel(__('subscriptions.suggest_add'))
            ->modalWidth(Width::TwoExtraLarge)
            ->visible(fn (Get $get) => self::suggestionsFor($get) !== [])
            ->schema(function (Get $get): array {
                $groups = self::suggestionsFor($get);

                return [
                    CheckboxList::make('pack_30')
                        ->label(__('subscriptions.pack_30'))
                        ->options($groups[30] ?? [])
                        ->columns(1)
                        ->visible(($groups[30] ?? []) !== []),

                    CheckboxList::make('pack_15')
                        ->label(__('subscriptions.pack_15'))
                        ->options($groups[15] ?? [])
                        ->columns(1)
                        ->visible(($groups[15] ?? []) !== []),
                ];
            })
            ->action(function (array $data, Get $get, Set $set): void {
                $picked = [
                    ...(array) ($data['pack_30'] ?? []),
                    ...(array) ($data['pack_15'] ?? []),
                ];

                if ($picked === []) {
                    return;
                }

                // ADDED to what is already there, never replacing it: the admin's own
                // choices are not the engine's to overwrite.
                $current = (array) ($get('selected_variants') ?? []);
                $merged = array_values(array_unique([...$current, ...$picked]));

                $set('selected_variants', $merged);

                Notification::make()
                    ->title(__('subscriptions.suggest_added', ['count' => count($merged) - count($current)]))
                    ->success()
                    ->send();
            });
    }

    /**
     * The engine's picks for this dog, grouped by pack size: [30 => [id => label], 15 => …].
     *
     * Empty when the dog has too little information to score — the button hides itself
     * rather than opening onto nothing.
     *
     * @return array<int, array<string, string>>
     */
    private static function suggestionsFor(Get $get): array
    {
        $dog = self::dogFromForm($get);
        $recommender = app(DogFoodRecommender::class);

        if (! $recommender->canRecommend($dog)) {
            return [];
        }

        $groups = [];

        foreach ($recommender->recommend($dog)['products'] as $entry) {
            foreach ([$entry['variant'], $entry['variant2']] as $index => $variant) {
                if ($variant === null) {
                    continue;
                }

                $label = VariantResolver::label($variant);

                // The engine's own first choice, marked — the rest are alternatives that
                // also fit, and an admin scanning two lists deserves to know which is which.
                if ($index === 0) {
                    $label = '★ '.$label.' — '.__('subscriptions.recommended');
                }

                $packSize = (int) ($variant->pack_size ?? 0);
                $groups[$packSize === 15 ? 15 : 30][(string) $variant->shopify_variant_id] = $label;
            }
        }

        return $groups;
    }

    /** What the engine says this dog needs, shown right above the picker. */
    private static function requirementHint(Get $get): string
    {
        $dog = self::dogFromForm($get);
        $recommender = app(DogFoodRecommender::class);

        if (! $recommender->canRecommend($dog)) {
            return __('subscriptions.no_recommendation');
        }

        $result = $recommender->recommend($dog);
        $best = $result['products'][0] ?? null;

        return $best === null
            ? __('subscriptions.calories_only', ['calories' => $result['calories']])
            : __('subscriptions.requirement', ['grams' => $best['benchmark'], 'calories' => $result['calories']]);
    }

    /**
     * Every variant in the catalogue — minus anything this dog reacts to.
     *
     * The weight and age rules are deliberately absent here: an admin adding a treat by
     * hand, or ticking "show the whole catalog", is making a choice the engine should not
     * argue with. An allergy is not that kind of rule. A food the dog reacts to has no
     * business being offered on any list, so it is filtered out even here.
     *
     * @return array<string, string>
     */
    private static function allVariantOptions(?Dog $dog = null): array
    {
        $recommender = app(DogFoodRecommender::class);

        return ProductVariant::query()
            ->with('product')
            ->orderBy('product_id')
            ->orderBy('position')
            ->get()
            ->reject(fn (ProductVariant $v) => $dog !== null
                && $v->product !== null
                && $recommender->isAllergenicFor($v->product, $dog))
            ->mapWithKeys(fn (ProductVariant $v) => [(string) $v->shopify_variant_id => VariantResolver::label($v)])
            ->all();
    }

    /** A throwaway Dog carrying the values currently in the form, for the engine to score. */
    private static function dogFromForm(Get $get): Dog
    {
        return new Dog([
            'weight' => $get('weight'),
            'age' => $get('age'),
            'activity' => $get('activity'),
            'body' => $get('body'),
            'neutered' => $get('neutered'),
            'allergies' => $get('allergies'),
        ]);
    }
}
