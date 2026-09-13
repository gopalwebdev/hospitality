<?php

namespace App\Filament\Platform\Resources\Users\Schemas;

use App\Models\User;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Account')
                    ->icon(Heroicon::OutlinedUser)
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('email')
                            ->label('Email address')
                            ->copyable(),
                        TextEntry::make('email_verified_at')
                            ->label('Email verified')
                            ->dateTime()
                            ->placeholder('Not yet — verified by their first sign-in code'),
                        TextEntry::make('created_at')
                            ->label('Account created')
                            ->dateTime(),
                    ])
                    ->columns(2),

                Section::make('Placement')
                    ->icon(Heroicon::OutlinedBuildingOffice2)
                    ->schema([
                        TextEntry::make('tenant.name')
                            ->label('Tenant')
                            ->badge()
                            ->color('gray')
                            ->placeholder('Product team — belongs to no tenant'),

                        IconEntry::make('is_admin')
                            ->label('The product team')
                            ->boolean()
                            ->helperText('This, not an empty tenant, is what grants every permission.'),

                        TextEntry::make('tenants.name')
                            ->label('Rostered at')
                            ->badge()
                            ->placeholder('No tenants')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Roles')
                    ->icon(Heroicon::OutlinedIdentification)
                    ->schema([
                        TextEntry::make('roles.name')
                            ->label('Roles')
                            ->badge()
                            ->placeholder('No roles')
                            ->columnSpanFull(),

                        TextEntry::make('permissions_summary')
                            ->label('Effective permissions')
                            ->state(fn (User $record): string => self::effectivePermissions($record))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * What this account may actually do, however it comes by it.
     *
     * The product team hold no roles: AppServiceProvider's Gate::before grants
     * them everything, so listing their roles' permissions would read as none.
     */
    private static function effectivePermissions(User $user): string
    {
        if ($user->isAdmin()) {
            return 'Every permission, granted by the product team rather than by a role.';
        }

        $permissions = $user->getAllPermissions()->pluck('name')->sort();

        return $permissions->isEmpty() ? 'None' : $permissions->implode(', ');
    }
}
