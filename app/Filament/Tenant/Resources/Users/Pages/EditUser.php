<?php

namespace App\Filament\Restaurant\Resources\Users\Pages;

use App\Actions\Restaurants\SetRestaurantUserRoles;
use App\Filament\Restaurant\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

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
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        // A disabled roles field is never dehydrated, so an absent key means
        // "leave them alone" rather than "clear them".
        $roleNames = array_key_exists('roles', $data)
            ? array_values((array) $data['roles'])
            : null;

        unset($data['roles']);

        $record->update($data);

        if ($roleNames !== null && $record instanceof User) {
            app(SetRestaurantUserRoles::class)($record, $roleNames);
        }

        return $record->refresh();
    }
}
