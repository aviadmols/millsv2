<?php

namespace App\Filament\Forms;

use App\Models\Dog;
use App\Modules\MillsSubscriptions\Services\Recommendation\DogFoodRecommender;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;

/**
 * The dog's sensitivities — picked from the classes the catalog actually carries, never
 * typed.
 *
 * A sensitivity is only real if a product carries the matching class: the recommender
 * excludes a food by comparing the dog's allergy list against the product's type and tags.
 * Free text let an admin write "chicken", or "עוף " with a trailing space, or a flavour
 * this dog was never offered — each of which excludes nothing at all while reading, on the
 * screen, exactly like a sensitivity that works.
 *
 * The options come from the foods THIS dog could otherwise eat (its weight and age already
 * applied), so picking one always removes a product that was on the table a moment ago.
 *
 * The stored value stays a comma-separated string. That is what the storefront sends, what
 * the v1 import wrote, and what the recommender reads — the picker changes how it is
 * chosen, not what it is.
 */
class AllergySelect
{
    public static function make(string $name = 'allergies'): Select
    {
        return Select::make($name)
            ->label(__('subscriptions.allergies'))
            ->helperText(__('subscriptions.allergies_help'))
            ->multiple()
            ->searchable()
            ->options(fn (Get $get) => app(DogFoodRecommender::class)->allergenOptions(new Dog([
                'weight' => $get('weight'),
                'age' => $get('age'),
                'allergies' => $get($name),
            ])))
            // Feeding the recommender below it: change a sensitivity and the product list
            // re-filters immediately, so the effect of the choice is visible.
            ->live()
            ->formatStateUsing(fn ($state) => self::split($state))
            ->dehydrateStateUsing(fn ($state) => self::join($state));
    }

    /** @return list<string> */
    public static function split(mixed $state): array
    {
        if (is_array($state)) {
            return array_values(array_filter(array_map(fn ($v) => trim((string) $v), $state)));
        }

        $raw = trim((string) ($state ?? ''));

        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/[,;]+/', $raw) ?: [])));
    }

    public static function join(mixed $state): ?string
    {
        $list = self::split($state);

        // NULL, not '' — the column is nullable and "no sensitivities" is an absence, not
        // an empty string that every `!== ''` check downstream has to remember to handle.
        return $list === [] ? null : implode(', ', $list);
    }
}
