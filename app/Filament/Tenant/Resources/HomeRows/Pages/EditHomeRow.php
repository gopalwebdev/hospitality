<?php

namespace App\Filament\Restaurant\Resources\HomeRows\Pages;

use App\Filament\Restaurant\Resources\HomeRows\HomeRowResource;
use App\Filament\Restaurant\Resources\HomeRows\Schemas\HomeRowForm;
use App\Models\HomeRow;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

/**
 * One row, and the tiles inside it.
 *
 * A page rather than a modal, because the tiles relation manager hangs under
 * the form — shaping the row and filling it are the same sitting.
 */
class EditHomeRow extends EditRecord
{
    protected static string $resource = HomeRowResource::class;

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

        return $record instanceof HomeRow
            ? HomeRowForm::fillTranslations($data, $record)
            : $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->icon(Heroicon::OutlinedTrash)
                ->modalDescription(__('panel.rows.delete_warning')),
        ];
    }
}
