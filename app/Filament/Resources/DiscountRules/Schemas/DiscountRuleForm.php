<?php

namespace App\Filament\Resources\DiscountRules\Schemas;

use App\Models\DiscountRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\ShopifyId;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * A rule reads as a sentence: give THIS much off, WHEN the order looks like this.
 *
 * The conditions are deliberately all optional and all on one screen. A rule with nothing
 * filled in is a store-wide discount, which is the simplest thing anyone wants and should
 * not require understanding the rest of the form; every field added narrows it.
 */
class DiscountRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('discounts.section_what'))
                ->description(__('discounts.section_what_help'))
                ->schema([
                    TextInput::make('name')
                        ->label(__('discounts.name'))
                        // It is printed on the customer's Shopify order, so it is not an
                        // internal code — it is what they read on the invoice.
                        ->helperText(__('discounts.name_help'))
                        ->required()
                        ->maxLength(120),

                    TextInput::make('percent')
                        ->label(__('discounts.percent'))
                        ->numeric()
                        ->required()
                        ->minValue(0.01)
                        ->maxValue(100)
                        ->suffix('%'),

                    Radio::make('scope')
                        ->label(__('discounts.scope'))
                        ->options([
                            DiscountRule::SCOPE_ORDER => __('discounts.scope_order'),
                            DiscountRule::SCOPE_MATCHING_LINES => __('discounts.scope_matching'),
                        ])
                        ->descriptions([
                            DiscountRule::SCOPE_ORDER => __('discounts.scope_order_help'),
                            DiscountRule::SCOPE_MATCHING_LINES => __('discounts.scope_matching_help'),
                        ])
                        ->default(DiscountRule::SCOPE_ORDER)
                        ->required(),

                    Toggle::make('is_active')
                        ->label(__('discounts.is_active'))
                        ->helperText(__('discounts.is_active_help'))
                        ->default(true),
                ]),

            Section::make(__('discounts.section_when'))
                ->description(__('discounts.section_when_help'))
                ->schema([
                    Select::make('frequency_months')
                        ->label(__('discounts.frequency'))
                        ->helperText(__('discounts.any_help'))
                        ->options([
                            1 => __('subscriptions.monthly'),
                            2 => __('subscriptions.every_2_months'),
                        ])
                        ->placeholder(__('discounts.any')),

                    Select::make('pack_sizes')
                        ->label(__('discounts.pack_sizes'))
                        ->helperText(__('discounts.any_help'))
                        ->multiple()
                        ->options([15 => __('discounts.pack_15'), 30 => __('discounts.pack_30')])
                        ->placeholder(__('discounts.any')),

                    TagsInput::make('tags')
                        ->label(__('discounts.tags'))
                        ->helperText(__('discounts.tags_help'))
                        ->placeholder(__('discounts.any')),

                    Select::make('product_ids')
                        ->label(__('discounts.products'))
                        ->helperText(__('discounts.any_help'))
                        ->multiple()
                        ->searchable()
                        ->optionsLimit(500)
                        ->options(fn () => Product::query()
                            ->orderBy('title')
                            ->pluck('title', 'shopify_product_id')
                            ->all())
                        ->placeholder(__('discounts.any')),

                    Select::make('variant_ids')
                        ->label(__('discounts.variants'))
                        ->helperText(__('discounts.variants_help'))
                        ->multiple()
                        ->searchable()
                        ->optionsLimit(500)
                        ->getSearchResultsUsing(fn (string $search) => ProductVariant::query()
                            ->with('product')
                            ->where('title', 'like', "%{$search}%")
                            ->orWhere('sku', 'like', "%{$search}%")
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn (ProductVariant $v) => [
                                (string) $v->shopify_variant_id => self::variantLabel($v),
                            ])
                            ->all())
                        ->getOptionLabelsUsing(fn (array $values) => ProductVariant::query()
                            ->with('product')
                            ->whereIn('shopify_variant_id', array_map(
                                fn ($v) => ShopifyId::numeric((string) $v),
                                $values,
                            ))
                            ->get()
                            ->mapWithKeys(fn (ProductVariant $v) => [
                                (string) $v->shopify_variant_id => self::variantLabel($v),
                            ])
                            ->all())
                        ->placeholder(__('discounts.any')),

                    TextInput::make('priority')
                        ->label(__('discounts.priority'))
                        ->helperText(__('discounts.priority_help'))
                        ->numeric()
                        ->default(0),
                ])
                // The one sentence that prevents the commonest misreading of this screen.
                ->footerActions([])
                ->hintIcon('heroicon-o-information-circle')
                ->hint(fn (Get $get) => __('discounts.matching_note')),
        ]);
    }

    private static function variantLabel(ProductVariant $variant): string
    {
        $product = $variant->product?->title ?: '—';
        $title = $variant->title ?: $variant->sku ?: (string) $variant->shopify_variant_id;

        return $product.' · '.$title;
    }
}
