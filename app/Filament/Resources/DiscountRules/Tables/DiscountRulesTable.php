<?php

namespace App\Filament\Resources\DiscountRules\Tables;

use App\Models\DiscountRule;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DiscountRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('priority', 'desc')
            ->emptyStateHeading(__('discounts.empty'))
            ->emptyStateDescription(__('discounts.empty_help'))
            ->columns([
                IconColumn::make('is_active')
                    ->label(__('discounts.is_active'))
                    ->boolean(),

                TextColumn::make('name')
                    ->label(__('discounts.name'))
                    ->searchable()
                    ->weight('bold'),

                TextColumn::make('percent')
                    ->label(__('discounts.percent'))
                    ->formatStateUsing(fn ($state) => rtrim(rtrim(number_format((float) $state, 2), '0'), '.').'%')
                    ->badge()
                    ->color('success'),

                TextColumn::make('scope')
                    ->label(__('discounts.scope'))
                    ->formatStateUsing(fn (string $state) => $state === DiscountRule::SCOPE_MATCHING_LINES
                        ? __('discounts.scope_matching')
                        : __('discounts.scope_order'))
                    ->badge()
                    ->color('gray'),

                // The conditions in one readable phrase — the question anyone scanning this
                // list is asking is "when does this one fire", not "which columns are set".
                TextColumn::make('conditions')
                    ->label(__('discounts.conditions'))
                    ->state(fn (DiscountRule $record) => self::conditions($record))
                    ->listWithLineBreaks()
                    ->limitList(3),

                TextColumn::make('priority')
                    ->label(__('discounts.priority'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /** @return list<string> */
    private static function conditions(DiscountRule $rule): array
    {
        $parts = [];

        if ($rule->frequency_months !== null) {
            $parts[] = __('subscriptions.frequency').': '.((int) $rule->frequency_months === 2
                ? __('subscriptions.every_2_months')
                : __('subscriptions.monthly'));
        }

        if (! empty($rule->pack_sizes)) {
            $parts[] = __('discounts.pack_sizes').': '.implode(', ', (array) $rule->pack_sizes);
        }

        if (! empty($rule->tags)) {
            $parts[] = __('discounts.tags').': '.implode(', ', (array) $rule->tags);
        }

        if (! empty($rule->product_ids)) {
            $parts[] = __('discounts.products').': '.count((array) $rule->product_ids);
        }

        if (! empty($rule->variant_ids)) {
            $parts[] = __('discounts.variants').': '.count((array) $rule->variant_ids);
        }

        // Exclusions change what a rule does more than any of the above, so they are never
        // hidden behind "applies to all".
        $excluded = count((array) $rule->excluded_product_ids) + count((array) $rule->excluded_variant_ids);

        if ($excluded > 0) {
            $parts[] = __('discounts.excluded_count', ['count' => $excluded]);
        }

        // No conditions is a real, deliberate state — say so rather than showing a blank.
        return $parts === [] ? [__('discounts.applies_to_all')] : $parts;
    }
}
