<?php

namespace App\Filament\Tenant\Resources\Menus\Pages;

use App\Filament\Tenant\Resources\Menus\MenuResource;
use App\Filament\Tenant\Resources\Menus\Schemas\MenuForm;
use App\Models\Menu;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

/**
 * What a menu is called, when it is served and whether guests see it.
 *
 * A page rather than a modal because it is one of the menu's two tabs; what the
 * menu holds is edited on the other one, ArrangeMenu.
 */
class EditMenu extends EditRecord
{
    #[\Override]
    protected static string $resource = MenuResource::class;

    /**
     * Spatie hands back one language for a translated attribute, and this form
     * edits all of them — see .ai/rules/filament.md.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        return $record instanceof Menu ? MenuForm::fillTranslations($data, $record) : $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->icon(Heroicon::OutlinedTrash)
                ->modalDescription(__('panel.menus.delete_warning')),
        ];
    }
}
