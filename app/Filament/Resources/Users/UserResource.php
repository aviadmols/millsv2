<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Who can log in.
 *
 * Every row in `users` is a trusted admin — `User::canAccessPanel()` returns true for all of
 * them — so creating one here hands somebody the keys to the subscriptions, the customers and
 * the "charge now" button. There is no role system to soften that, which is exactly why the
 * screen says so out loud rather than looking like an ordinary CRUD form.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    // Last in the sidebar: this is a settings-shaped thing, not day-to-day work.
    protected static ?int $navigationSort = 90;

    public static function getNavigationLabel(): string
    {
        return __('users.title');
    }

    public static function getModelLabel(): string
    {
        return __('users.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('users.title');
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
