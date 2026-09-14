<?php

namespace App\Filament\Platform\Resources\Roles\Pages;

use App\Actions\Roles\SetRolePermissions;
use App\Filament\Platform\Resources\Roles\RoleResource;
use App\Filament\Platform\Resources\Roles\Schemas\RoleForm;
use App\Models\Role;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class EditRole extends EditRecord
{
    #[\Override]
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->icon(Heroicon::OutlinedTrash),
        ];
    }

    /**
     * Show the permissions the role currently holds, in their categories.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        $permissions = $record instanceof Role
            ? $record->permissions->all()
            : [];

        return [...$data, ...RoleForm::spreadPermissionIds(...$permissions)];
    }

    /**
     * Save the role, then sync its permissions through Spatie.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $permissionIds = RoleForm::pullPermissionIds($data);

        $record->update($data);

        if ($record instanceof Role) {
            app(SetRolePermissions::class)($record, $permissionIds);
        }

        return $record->refresh();
    }
}
