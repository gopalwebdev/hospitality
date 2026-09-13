<?php

namespace App\Filament\Restaurant\Resources\Menus\Pages;

use App\Filament\Restaurant\Resources\Menus\MenuResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

/**
 * Menus are few and arranged together, so they are created and edited in
 * modals on this page rather than on pages of their own — the same shape as
 * ListMenuCategories.
 */
class ListMenus extends ListRecords
{
    protected static string $resource = MenuResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('panel.menus.create'))
                ->icon(Heroicon::OutlinedPlus),
        ];
    }
}
