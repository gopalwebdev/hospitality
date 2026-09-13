<?php

namespace App\Filament\Platform\Resources\Roles;

use App\Filament\Platform\Resources\Roles\Pages\CreateRole;
use App\Filament\Platform\Resources\Roles\Pages\EditRole;
use App\Filament\Platform\Resources\Roles\Pages\ListRoles;
use App\Filament\Platform\Resources\Roles\Pages\ViewRole;
use App\Filament\Platform\Resources\Roles\Schemas\RoleForm;
use App\Filament\Platform\Resources\Roles\Schemas\RoleInfolist;
use App\Filament\Platform\Resources\Roles\Tables\RolesTable;
use App\Models\Role;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The roles every tenant assigns from, on the product team panel.
 *
 * Access is RolePolicy's business. What this class adds is the one rule a
 * policy cannot express: whether a *particular* role may be deleted. A built-in
 * role — one declared in App\Enums\Role — may have its permissions edited
 * freely, but never renamed or deleted, because code refers to it by name.
 *
 * That guard is stated here rather than in the policy because the Gate::before
 * in AppServiceProvider answers true for the product team before any policy
 * method runs, and the product team is exactly who reaches this page.
 */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Access control';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return RoleForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return RoleInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RolesTable::configure($table);
    }

    /**
     * A role is deletable only when nothing depends on it: Role::undeletableReason()
     * owns that question, and the table shows whatever it says.
     */
    public static function canDelete(Model $record): bool
    {
        return (! $record instanceof Role || $record->undeletableReason() === null) && parent::canDelete($record);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'view' => ViewRole::route('/{record}'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
