<?php

namespace App\Filament\Tenant\Resources\Charges\Pages;

use App\Filament\Tenant\Resources\Charges\ChargeResource;
use App\Filament\Tenant\Resources\Charges\Schemas\ChargeForm;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

/**
 * Every charge this tenant levies, in the order a guest reads them.
 */
class ListCharges extends ListRecords
{
    protected static string $resource = ChargeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('panel.charges.create'))
                ->icon(Heroicon::OutlinedPlus)
                ->mutateDataUsing(fn (array $data): array => ChargeForm::storeValue($data)),
        ];
    }
}
