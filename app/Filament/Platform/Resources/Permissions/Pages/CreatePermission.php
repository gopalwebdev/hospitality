<?php

namespace App\Filament\Platform\Resources\Permissions\Pages;

use App\Filament\Platform\Resources\Permissions\PermissionResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePermission extends CreateRecord
{
    #[\Override]
    protected static string $resource = PermissionResource::class;
}
