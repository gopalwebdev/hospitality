<?php

namespace App\Filament\Platform\Resources\Permissions;

use App\Filament\Platform\Resources\Permissions\Pages\CreatePermission;
use App\Filament\Platform\Resources\Permissions\Pages\EditPermission;
use App\Filament\Platform\Resources\Permissions\Pages\ListPermissions;
use App\Filament\Platform\Resources\Permissions\Pages\ViewPermission;
use App\Filament\Platform\Resources\Permissions\Schemas\PermissionForm;
use App\Filament\Platform\Resources\Permissions\Schemas\PermissionInfolist;
use App\Filament\Platform\Resources\Permissions\Tables\PermissionsTable;
use App\Models\Permission;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The permissions roles are built from, on the product team panel.
 *
 * As with roles, a built-in permission — one declared in App\Enums\Permission
 * — is read-only here: its name is what the application checks with can(), so
 * renaming or deleting it revokes access silently. The guard sits on the
 * resource rather than the policy because Gate::before answers for an
 * admin before any policy method runs.
 */
class PermissionResource extends Resource
{
    protected static ?string $model = Permission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Access control';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return PermissionForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PermissionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PermissionsTable::configure($table);
    }

    public static function canEdit(Model $record): bool
    {
        return (! $record instanceof Permission || ! $record->isBuiltIn()) && parent::canEdit($record);
    }

    /**
     * A permission is deletable only when no role holds it and the code does
     * not declare it. Permission::undeletableReason() owns that question.
     */
    public static function canDelete(Model $record): bool
    {
        return (! $record instanceof Permission || $record->undeletableReason() === null) && parent::canDelete($record);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPermissions::route('/'),
            'create' => CreatePermission::route('/create'),
            'view' => ViewPermission::route('/{record}'),
            'edit' => EditPermission::route('/{record}/edit'),
        ];
    }
}
