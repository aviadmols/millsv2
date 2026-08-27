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
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * The full subscription editor — owner, plan, order links, and each dog's PRODUCTS.
 *
 * The product picker is dog-aware: the options are the variants the recommender says
 * this dog may actually eat (a 3 kg dog is never offered a 500 g portion), ordered by
 * fit, with the recommended one marked ★ and the dog's computed requirement shown.
 * An admin who needs to override can flip "show the whole catalog" — the filter helps,
 * it never blocks.
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
                                    ->options(fn (Get $get) => self::allVariantOptions(self::dogFromForm($get)))
                                    ->suffixAction(
                                        FormAction::make('suggestVariants')
                                            ->label(__('subscriptions.suggest_products'))
                                            ->tooltip(__('subscriptions.suggest_products_help'))
                                            ->icon(Heroicon::OutlinedSparkles)
                                            ->action(function (Get $get, Set $set): void {
                                                $suggested = array_keys(self::variantOptions($get));

                                                if ($suggested === []) {
                                                    Notification::make()
                                                        ->title(__('subscriptions.suggest_none'))
                                                        ->warning()
                                                        ->send();

                                                    return;
                                                }

                                                // ADDED to what is there, never replacing it:
                                                // the admin's own choices are not the engine's
                                                // to overwrite.
                                                $current = (array) ($get('selected_variants') ?? []);
                                                $merged = array_values(array_unique([...$current, ...$suggested]));

                                                $set('selected_variants', $merged);

                                                Notification::make()
                                                    ->title(__('subscriptions.suggest_added', [
                                                        'count' => count($merged) - count($current),
                                                    ]))
                                                    ->success()
                                                    ->send();
                                            })
                                    )
                                    ->columnSpanFull(),

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
     * What the engine would pick for this dog — the SUGGESTION behind the ✨ button.
     *
     * No longer what the picker offers: the picker shows the whole catalogue, because an
     * admin choosing by hand knows things the engine does not. This is an opinion on
     * request, which is a different thing from a filter.
     *
     * Empty when the dog has too little information to score — the button then says so
     * rather than adding nothing and looking broken.
     *
     * @return array<string, string>
     */
    private static function variantOptions(Get $get): array
    {
        $dog = self::dogFromForm($get);
        $recommender = app(DogFoodRecommender::class);

        if (! $recommender->canRecommend($dog)) {
            return [];
        }

        $result = $recommender->recommend($dog);
        $options = [];

        foreach ($result['products'] as $entry) {
            foreach ([$entry['variant'], $entry['variant2']] as $index => $variant) {
                if ($variant === null) {
                    continue;
                }

                $label = VariantResolver::label($variant);
                if ($index === 0) {
                    $label = '★ '.$label.' — '.__('subscriptions.recommended');
                }

                $options[(string) $variant->shopify_variant_id] = $label;
            }
        }

        // The suggestion is what the ENGINE picked — not what is already selected. Folding
        // the current choice in here would make the button add back what is already there
        // and report a number that means nothing.
        return $options;
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
