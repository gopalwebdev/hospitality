<?php

namespace App\Filament\Platform\Resources\Roles\Pages;

use App\Actions\Roles\SetRolePermissions;
use App\Filament\Platform\Resources\Roles\RoleResource;
use App\Filament\Platform\Resources\Roles\Schemas\RoleForm;
use App\Models\Role;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateRole extends CreateRecord
{
    #[\Override]
    protected static string $resource = RoleResource::class;

    /**
     * Create the role, then set its permissions through Spatie.
     *
     * The permissions are not bound to the relationship, so they arrive as ids
     * spread across one key per category. RoleForm collects them; and
     * SetRolePermissions is what puts them on and flushes the permission cache.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $permissionIds = RoleForm::pullPermissionIds($data);

        $role = static::getModel()::query()->create($data);

        if ($role instanceof Role) {
            app(SetRolePermissions::class)($role, $permissionIds);
        }

        return $role->refresh();
    }
}
