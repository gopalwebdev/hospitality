<?php

namespace App\Filament\Platform\Resources\Users\Pages;

use App\Filament\Platform\Resources\Users\UserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListUsers extends ListRecords
{
    #[\Override]
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New account')
                ->icon(Heroicon::OutlinedUserPlus),
        ];
    }
}
