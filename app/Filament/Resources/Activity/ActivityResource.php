<?php

namespace App\Filament\Resources\Activity;

use App\Filament\Resources\Activity\Tables\ActivityTable;
use App\Models\ActivityEvent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;

/**
 * Everything that happened, in one place, in business language.
 *
 * There were two logs and neither answered the question people actually ask. `system_logs` is
 * the engineering trail — categories like "shopify" and "api", useful when something is
 * broken. `activity_events` is the business one, but it had no screen at all, so the events
 * that matter — a quiz became a customer, a card was charged, an admin paused a subscription
 * — were written and never read.
 *
 * This is that screen: who did what, to which customer, and when. Read-only, because the
 * whole point of a record of what happened is that it cannot be rewritten afterwards.
 */
class ActivityResource extends Resource
{
    protected static ?string $model = ActivityEvent::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bolt';

    // Directly under Subscriptions: this is a daily-work screen, not a settings one.
    protected static ?int $navigationSort = 12;

    public static function getNavigationLabel(): string
    {
        return __('activity.title');
    }

    public static function getModelLabel(): string
    {
        return __('activity.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('activity.title');
    }

    public static function table(Table $table): Table
    {
        return ActivityTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivity::route('/'),
        ];
    }
}
