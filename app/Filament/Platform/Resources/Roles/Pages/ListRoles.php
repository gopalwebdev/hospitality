<?php

namespace App\Filament\Platform\Resources\Roles\Pages;

use App\Filament\Platform\Resources\Roles\RoleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListRoles extends ListRecords
{
    #[\Override]
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New role')
                ->icon(Heroicon::OutlinedPlus),
        ];
    }
}
