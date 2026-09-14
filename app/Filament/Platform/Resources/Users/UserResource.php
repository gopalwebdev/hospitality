<?php

namespace App\Filament\Platform\Resources\Users;

use App\Filament\Platform\Resources\Users\Pages\CreateUser;
use App\Filament\Platform\Resources\Users\Pages\EditUser;
use App\Filament\Platform\Resources\Users\Pages\ListUsers;
use App\Filament\Platform\Resources\Users\Pages\ViewUser;
use App\Filament\Platform\Resources\Users\Schemas\UserForm;
use App\Filament\Platform\Resources\Users\Schemas\UserInfolist;
use App\Filament\Platform\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Every account on the platform, from the product team panel.
 *
 * This is the unscoped view of users: no tenant relationship is named, so
 * unlike the tenant panel's own users resource it lists the product team and
 * every tenant's roster alike, and it is the only place an account is
 * created with a tenant, made the product team, or deleted outright.
 *
 * Two guards live here rather than in UserPolicy. AppServiceProvider's
 * Gate::before answers true for an admin before any policy method runs, so
 * a policy is the wrong place for a rule that has to bind the product team too:
 *
 * - the page is the product team only, whatever user.manage a tenant role carries
 * - nobody deletes their own account, which is the one delete with no way back
 */
class UserResource extends Resource
{
    #[\Override]
    protected static ?string $model = User::class;

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    #[\Override]
    protected static string|UnitEnum|null $navigationGroup = 'Access control';

    #[\Override]
    protected static ?int $navigationSort = 30;

    #[\Override]
    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return UserInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    /**
     * The product team only, including the navigation item.
     *
     * user.manage is held by tenant roles, so leaning on the policy alone
     * would offer this page to a tenant owner the moment they could reach
     * the panel at all.
     */
    public static function canAccess(): bool
    {
        return Filament::auth()->user()?->isAdmin() ?? false;
    }

    /**
     * Deleting your own account would lock the last admin out of the
     * platform, so the panel never offers it.
     */
    public static function canDelete(Model $record): bool
    {
        return ! Filament::auth()->user()?->is($record) && parent::canDelete($record);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'view' => ViewUser::route('/{record}'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
