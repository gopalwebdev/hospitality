<?php

namespace App\Filament\Restaurant\Resources\Users;

use App\Filament\Restaurant\Resources\Users\Pages\CreateUser;
use App\Filament\Restaurant\Resources\Users\Pages\EditUser;
use App\Filament\Restaurant\Resources\Users\Pages\ListUsers;
use App\Filament\Restaurant\Resources\Users\Schemas\UserForm;
use App\Filament\Restaurant\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The people who staff the restaurant whose panel this is.
 *
 * Scoping is Filament's: the panel has a tenant, and naming the many-to-many
 * back to it means every query here is limited to the restaurant in the
 * subdomain, and anyone created here joins its roster. Who may use the page at
 * all is UserPolicy's business, through the user.manage permission.
 *
 * Accounts are platform-wide, so this resource never deletes one. Taking
 * someone off the roster detaches them, which is what the table action does.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $tenantOwnershipRelationshipName = 'restaurants';

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
