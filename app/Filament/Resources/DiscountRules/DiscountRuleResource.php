<?php

namespace App\Filament\Resources\DiscountRules;

use App\Filament\Resources\DiscountRules\Schemas\DiscountRuleForm;
use App\Filament\Resources\DiscountRules\Tables\DiscountRulesTable;
use App\Models\DiscountRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * The discounts on the recurring cycle, and what turns each one on.
 *
 * Before this there was one number per subscription, stamped at signup and never revisited.
 * "10% on two-month plans" or "5% off the premium range" was expressible only by editing
 * every subscription by hand, which means it was never done and the store had exactly one
 * discount policy: whatever v1 happened to write years ago.
 */
class DiscountRuleResource extends Resource
{
    protected static ?string $model = DiscountRule::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    // With billing, not with the daily subscription work: this is policy, set rarely.
    protected static ?int $navigationSort = 30;

    public static function getNavigationLabel(): string
    {
        return __('discounts.title');
    }

    public static function getModelLabel(): string
    {
        return __('discounts.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('discounts.title');
    }

    public static function form(Schema $schema): Schema
    {
        return DiscountRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DiscountRulesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDiscountRules::route('/'),
            'create' => Pages\CreateDiscountRule::route('/create'),
            'edit' => Pages\EditDiscountRule::route('/{record}/edit'),
        ];
    }
}
