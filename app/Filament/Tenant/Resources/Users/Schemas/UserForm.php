<?php

namespace App\Filament\Tenant\Resources\Users\Schemas;

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
                // account joins this tenant on that account rather than
                // being refused. AddUserToTenant does the joining.
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->helperText('Sign-in codes go here. Someone who already has an account keeps it and simply joins this tenant.'),

                // Roles are not bound to the relationship: they go through
                // SetTenantUserRoles, which is what keeps a tenant from
                // handing out product team access or reaching into another
                // tenant's staff.
                CheckboxList::make('roles')
                    ->options(fn (): array => once(fn (): array => Role::query()
                        ->assignableWithinTenant()
                        ->orderBy('name')
                        ->pluck('name', 'name')
                        ->all()))
                    ->columns(2)
                    ->columnSpanFull()
                    ->disabled(fn (?User $record): bool => self::staffsSeveralTenants($record))
                    ->helperText(fn (?User $record): string => self::staffsSeveralTenants($record)
                        ? 'This person staffs more than one tenant, so only the product team can change their roles.'
                        : 'Roles carrying product team permissions are never offered here.'),
            ]);
    }

    /**
     * Asked twice while the form renders — to disable the roles and to say why.
     */
    private static function staffsSeveralTenants(?User $record): bool
    {
        return $record instanceof User && $record->staffsSeveralTenants();
    }
}
