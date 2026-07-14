<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('users.title'))
                    ->description(__('users.help'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('users.name'))
                            ->required()
                            ->maxLength(255),

                        TextInput::make('email')
                            ->label(__('users.email'))
                            ->email()
                            ->required()
                            ->maxLength(255)
                            // Two logins on one address is an account nobody can reason about.
                            ->unique(ignoreRecord: true),

                        TextInput::make('password')
                            ->label(__('users.password'))
                            ->password()
                            ->revealable()
                            ->confirmed()
                            ->rule(Password::default())
                            // Required when creating; on an edit, an empty box means "leave the
                            // password alone" — see dehydration below.
                            ->required(fn (string $operation) => $operation === 'create')
                            ->helperText(fn (string $operation) => $operation === 'edit'
                                ? __('users.password_edit_help')
                                : null)
                            /*
                             * Not dehydrated when blank — otherwise editing a user's NAME would
                             * save an empty password and lock them out of their own account.
                             *
                             * The plain value is passed straight through: the model casts
                             * `password` to `hashed`, which hashes it on the way in. Hashing it
                             * here as well would store a hash of a hash.
                             */
                            ->dehydrated(fn (?string $state) => filled($state)),

                        TextInput::make('password_confirmation')
                            ->label(__('users.password_confirmation'))
                            ->password()
                            ->revealable()
                            ->required(fn (string $operation) => $operation === 'create')
                            ->dehydrated(false),   // a confirmation is not a column
                    ]),
            ]);
    }
}
