<?php

namespace App\Filament\Platform\Resources\Users\Schemas;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Account')
                    ->description('Who this is and where their sign-in codes go.')
                    ->icon(Heroicon::OutlinedUser)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->prefixIcon(Heroicon::OutlinedUser),

                        TextInput::make('email')
                            ->label('Email address')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(User::class, 'email', ignoreRecord: true)
                            ->prefixIcon(Heroicon::OutlinedEnvelope)
                            ->helperText('Accounts carry no password. Sign-in codes are emailed here.'),
                    ])
                    ->columns(2),

                Section::make('Placement')
                    ->description('Which tenant this account belongs to, and whether it is on the product team.')
                    ->icon(Heroicon::OutlinedBuildingOffice2)
                    ->schema([
                        // Null is what "Product team" means on the users table. It
                        // grants nothing by itself, which is why the toggle
                        // below is a separate answer rather than derived here.
                        // Options rather than ->relationship(): the create and
                        // edit pages hand the id to an action that writes the
                        // column and the tenant roster together, and a
                        // bound relationship would write the column on its own.
                        //
                        // Where an account belongs is settled when it is opened.
                        // Moving one to another tenant would carry its roles
                        // across with it, and moving one to the product team
                        // would hand the whole platform to a single tenant's
                        // admin — so it is offered once and read-only after.
                        Select::make('tenant_id')
                            ->label('Tenant')
                            // once(): asked more than once per request while the
                            // form is built and validated.
                            ->options(fn (): array => once(fn (): array => Tenant::query()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all()))
                            ->searchable()
                            ->live()
                            ->placeholder('Product team — no tenant')
                            ->prefixIcon(Heroicon::OutlinedBuildingStorefront)
                            ->disabled(fn (?User $record): bool => $record instanceof User)
                            ->dehydrated(fn (?User $record): bool => ! $record instanceof User)
                            ->helperText(fn (?User $record): string => $record instanceof User
                                ? 'Settled when the account was opened. An account never moves between tenants, or to the product team.'
                                : 'Leave empty for the product team. Setting it also puts them on that tenant\'s roster, and cannot be changed later.'),

                        Toggle::make('is_super_admin')
                            ->label('The product team')
                            ->inline(false)
                            ->disabled(fn (?User $record, Get $get): bool => self::isSignedInUser($record) || filled($get('tenant_id')))
                            ->dehydrated(fn (?User $record, Get $get): bool => ! self::isSignedInUser($record) && blank($get('tenant_id')))
                            ->helperText(fn (Get $get): string => filled($get('tenant_id'))
                                ? 'Not available to an account that belongs to a tenant — the product team belong to no tenant at all.'
                                : 'Grants every permission on every tenant. This, not an empty tenant, is what makes a super admin.'),
                    ])
                    ->columns(2),

                Section::make('Roles')
                    ->description('What this account may do inside a tenant.')
                    ->icon(Heroicon::OutlinedIdentification)
                    ->schema([
                        // Every role, including any carrying a product team
                        // permission: deciding that is exactly what this panel
                        // is for. A tenant panel is offered a filtered list
                        // instead, by SetTenantUserRoles.
                        CheckboxList::make('roles')
                            ->options(fn (): array => once(fn (): array => Role::query()
                                ->orderBy('name')
                                ->pluck('name', 'name')
                                ->all()))
                            ->searchable()
                            ->bulkToggleable()
                            ->columns(2)
                            ->columnSpanFull()
                            ->helperText('Roles are held per account, not per tenant: someone staffing two tenants carries these at both.'),
                    ]),
            ]);
    }

    /**
     * Whether this is the account of whoever is looking at the form.
     *
     * Nobody takes their own product team badge off: it is the one change
     * that can lock the last super admin out of the platform.
     */
    private static function isSignedInUser(?User $record): bool
    {
        return Filament::auth()->user()?->is($record) ?? false;
    }
}
