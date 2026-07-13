<?php

namespace App\Filament\Resources\Subscriptions\Schemas;

use App\Models\Dog;
use App\Models\ProductVariant;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Services\Recommendation\DogFoodRecommender;
use App\Modules\MillsSubscriptions\Support\VariantResolver;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

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

                                TextInput::make('allergies')
                                    ->label(__('subscriptions.allergies'))
                                    ->helperText(__('subscriptions.allergies_help'))
                                    ->columnSpan(2)
                                    ->live(debounce: 700),

                                Toggle::make('show_all_products')
                                    ->label(__('subscriptions.show_all_products'))
                                    ->dehydrated(false)     // a UI switch, not a dog attribute
                                    ->live(),

                                Select::make('selected_variants')
                                    ->label(__('subscriptions.products'))
                                    ->helperText(fn (Get $get) => self::requirementHint($get))
                                    ->multiple()
                                    ->searchable()
                                    ->options(fn (Get $get) => self::variantOptions($get))
                                    ->columnSpanFull(),

                                Select::make('addons_products')
                                    ->label(__('subscriptions.addons'))
                                    ->multiple()
                                    ->searchable()
                                    // Add-ons are a free customer choice — never weight-filtered.
                                    ->options(fn () => self::allVariantOptions())
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
     * The variants this dog may actually eat — recommended first, ★-marked.
     *
     * Whatever is already selected is always kept in the options; otherwise editing a
     * dog whose current product no longer passes the filter would silently blank it.
     *
     * @return array<string, string>
     */
    private static function variantOptions(Get $get): array
    {
        if ($get('show_all_products')) {
            return self::allVariantOptions();
        }

        $dog = self::dogFromForm($get);
        $recommender = app(DogFoodRecommender::class);

        if (! $recommender->canRecommend($dog)) {
            return self::allVariantOptions();
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

        return $options + self::currentSelection($get);
    }

    /** Keep the already-chosen variants selectable even if they fall outside the filter. */
    private static function currentSelection(Get $get): array
    {
        $selected = VariantResolver::resolve($get('selected_variants'));

        return $selected
            ->mapWithKeys(fn (ProductVariant $v) => [(string) $v->shopify_variant_id => VariantResolver::label($v)])
            ->all();
    }

    /** @return array<string, string> */
    private static function allVariantOptions(): array
    {
        return ProductVariant::query()
            ->with('product')
            ->orderBy('product_id')
            ->orderBy('position')
            ->get()
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
