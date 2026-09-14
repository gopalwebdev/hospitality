<?php

namespace App\Filament\Tenant\Resources\Users\Schemas;

use App\Models\Role;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

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
                    ->maxLength(255),

                // Roles are not bound to the relationship: they go through
                // SetTenantUserRoles, which is what keeps a tenant from
                // handing out product team access or reaching into another
                // tenant's staff. A role carrying a product team permission is
                // never an option here.
                Select::make('roles')
                    ->multiple()
                    ->options(fn (): array => once(fn (): array => Role::query()
                        ->assignableWithinTenant()
                        ->orderBy('name')
                        ->pluck('name', 'name')
                        ->all()))
                    ->columnSpanFull()
                    ->disabled(fn (?User $record): bool => self::staffsSeveralTenants($record))
                    // Why the box is locked, named on hover rather than as a
                    // line of prose under it (.ai/rules/filament.md).
                    ->hintIcon(
                        fn (?User $record): ?Heroicon => self::staffsSeveralTenants($record) ? Heroicon::OutlinedLockClosed : null,
                        tooltip: 'This person staffs more than one tenant, so only the product team can change their roles.',
                    ),
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
