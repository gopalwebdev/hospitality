<?php

namespace App\Filament\Tenant\Resources\Users;

use App\Filament\Tenant\Resources\Users\Pages\CreateUser;
use App\Filament\Tenant\Resources\Users\Pages\EditUser;
use App\Filament\Tenant\Resources\Users\Pages\ListUsers;
use App\Filament\Tenant\Resources\Users\Schemas\UserForm;
use App\Filament\Tenant\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The people who staff the tenant whose panel this is.
 *
 * Scoping is Filament's: the panel has a tenant, and naming the many-to-many
 * back to it means every query here is limited to the tenant in the
 * subdomain, and anyone created here joins its roster. Who may use the page at
 * all is UserPolicy's business, through the user.manage permission.
 *
 * Accounts are platform-wide, so this resource never deletes one. Taking
 * someone off the roster detaches them, which is what the table action does.
 */
class UserResource extends Resource
{
    #[\Override]
    protected static ?string $model = User::class;

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    #[\Override]
    protected static ?int $navigationSort = 10;

    #[\Override]
    protected static ?string $recordTitleAttribute = 'name';

    #[\Override]
    protected static ?string $tenantOwnershipRelationshipName = 'tenants';

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
