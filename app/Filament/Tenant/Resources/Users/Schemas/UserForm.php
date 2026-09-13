<?php

namespace App\Filament\Restaurant\Resources\Users\Schemas;

use App\Models\Role;
use App\Models\User;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                // Deliberately not unique: an address that already has an
                // account joins this restaurant on that account rather than
                // being refused. AddUserToRestaurant does the joining.
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->helperText('Sign-in codes go here. Someone who already has an account keeps it and simply joins this restaurant.'),

                // Roles are not bound to the relationship: they go through
                // SetRestaurantUserRoles, which is what keeps a restaurant from
                // handing out product team access or reaching into another
                // restaurant's staff.
                CheckboxList::make('roles')
                    ->options(fn (): array => once(fn (): array => Role::query()
                        ->assignableWithinRestaurant()
                        ->orderBy('name')
                        ->pluck('name', 'name')
                        ->all()))
                    ->columns(2)
                    ->columnSpanFull()
                    ->disabled(fn (?User $record): bool => self::staffsSeveralRestaurants($record))
                    ->helperText(fn (?User $record): string => self::staffsSeveralRestaurants($record)
                        ? 'This person staffs more than one restaurant, so only the product team can change their roles.'
                        : 'Roles carrying product team permissions are never offered here.'),
            ]);
    }

    /**
     * Whether this account is on more than one restaurant's roster.
     *
     * Asked twice while the form renders — to disable the roles and to say why
     * — so it is remembered for the request.
     */
    private static function staffsSeveralRestaurants(?User $record): bool
    {
        return $record instanceof User && $record->staffsSeveralRestaurants();
    }
}
