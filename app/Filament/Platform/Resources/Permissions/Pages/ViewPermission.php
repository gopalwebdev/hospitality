<?php

namespace App\Filament\Platform\Resources\Permissions\Pages;

use App\Filament\Platform\Resources\Permissions\PermissionResource;
use App\Models\Permission;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewPermission extends ViewRecord
{
    #[\Override]
    protected static string $resource = PermissionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->icon(Heroicon::OutlinedPencilSquare)
                ->visible(fn (Permission $record): bool => PermissionResource::canEdit($record)),
        ];
    }
}
