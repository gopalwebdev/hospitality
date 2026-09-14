<?php

namespace App\Filament\Tenant\Resources\MenuAddOnGroups\Pages;

use App\Filament\Tenant\Resources\MenuAddOnGroups\MenuAddOnGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/**
 * Every add-on group this tenant's items are customised with, made and edited in modals on the one list.
 */
class ManageMenuAddOnGroups extends ManageRecords
{
    #[\Override]
    protected static string $resource = MenuAddOnGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('panel.add_on_groups.create'))
                ->icon(Heroicon::OutlinedPlus)
                // The group beside its table of options.
                ->modalWidth(Width::SevenExtraLarge),
        ];
    }
}
