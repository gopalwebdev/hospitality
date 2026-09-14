<?php

namespace App\Filament\Platform\Resources\Users\Pages;

use App\Actions\Users\SetUserRoles;
use App\Filament\Platform\Resources\Users\UserResource;
use App\Models\Tenant;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    #[\Override]
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()
                ->icon(Heroicon::OutlinedEye),
            DeleteAction::make()
                ->icon(Heroicon::OutlinedTrash)
                ->visible(fn (User $record): bool => UserResource::canDelete($record)),
        ];
    }

    /**
     * Show the roles the account currently holds.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        $data['roles'] = $record instanceof User
            ? $record->roles->pluck('name')->all()
            : [];

        return $data;
    }

    /**
     * Save the account, then sync its roles and roster through the actions.
     *
     * Roles go through SetUserRoles rather than a bound relationship so
     * Spatie's permission cache is flushed with them; the roster is kept in step
     * with the tenant column here for the same reason CreateUserAccount does it
     * on the way in — a tenant nobody is rostered at cannot be opened.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        // A disabled field is never dehydrated, so an absent key means "leave
        // them alone" rather than "clear them".
        $roleNames = array_key_exists('roles', $data)
            ? array_values((array) $data['roles'])
            : null;

        unset($data['roles']);

        $record->update($data);

        if ($record instanceof User) {
            $this->syncRoster($record);

            if ($roleNames !== null) {
                app(SetUserRoles::class)($record, $roleNames);
            }
        }

        return $record->refresh();
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Account saved';
    }

    /**
     * Put the account on the roster of the tenant it now belongs to.
     *
     * Only the new tenant is attached; any other tenant they staff is left
     * alone, because belonging to one is not the same as being taken off the
     * others. Moving to the platform detaches nothing for the same reason.
     */
    private function syncRoster(User $user): void
    {
        if ($user->tenant_id === null) {
            return;
        }

        $tenant = Tenant::query()->find($user->tenant_id);

        if ($tenant instanceof Tenant) {
            $user->tenants()->syncWithoutDetaching([$tenant->getKey()]);
        }
    }
}
