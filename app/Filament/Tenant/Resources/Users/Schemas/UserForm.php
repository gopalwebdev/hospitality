<?php

namespace App\Filament\Tenant\Resources\Users\Schemas;

use App\Filament\Tenant\CurrentTenant;
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
                    ->helperText(fn (): string => 'Sign-in codes go here. Someone who already has an account keeps it and simply joins this '.CurrentTenant::noun().'.'),

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
                    // Not "more than one hotel": the other roster may be a
                    // tenant of another type, so this names none.
                    ->helperText(fn (?User $record): string => self::staffsSeveralTenants($record)
                        ? 'This person also works somewhere else on the platform, so only the product team can change their roles.'
                        : 'Roles carrying product team permissions are never offered here.'),
            ]);
    }

    /**
     * Whether this account is on more than one tenant's roster.
     *
     * Asked twice while the form renders — to disable the roles and to say why
     * — so it is remembered for the request.
     */
    private static function staffsSeveralTenants(?User $record): bool
    {
        return $record instanceof User && $record->staffsSeveralTenants();
    }
}
