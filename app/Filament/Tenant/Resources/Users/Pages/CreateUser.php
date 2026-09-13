<?php

namespace App\Filament\Tenant\Resources\Users\Pages;

use App\Actions\Tenants\AddUserToTenant;
use App\Filament\Tenant\Resources\Users\UserResource;
use App\Models\Tenant;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * Join this tenant, on an existing account where there is one.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $tenant = Filament::getTenant();

        throw_unless($tenant instanceof Tenant, LogicException::class, 'Adding a user requires a tenant.');

        return app(AddUserToTenant::class)(
            $tenant,
            (string) $data['name'],
            (string) $data['email'],
            array_values((array) ($data['roles'] ?? [])),
        );
    }
}
