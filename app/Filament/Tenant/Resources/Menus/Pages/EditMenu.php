<?php

namespace App\Filament\Restaurant\Resources\Menus\Pages;

use App\Filament\Restaurant\Resources\Menus\MenuResource;
use App\Filament\Restaurant\Resources\Menus\Schemas\MenuForm;
use App\Models\Menu;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

/**
 * One menu, and the dishes it leads with.
 *
 * A page rather than a modal, because the featured dishes relation manager
 * hangs under the form — naming a menu and choosing what it opens with are the
 * same sitting.
 */
class EditMenu extends EditRecord
{
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
