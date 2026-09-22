<?php

namespace App\Filament\Tenant\Resources\TaxCodes\Pages;

use App\Filament\Tenant\Resources\TaxCodes\Schemas\TaxCodeForm;
use App\Filament\Tenant\Resources\TaxCodes\TaxCodeResource;
use App\Models\TaxCode;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/**
 * Every code this tenant can file an item under: the catalogue, and its own.
 *
 * The resource turns Filament's tenancy off so the catalogue stays visible
 * (see TaxCodeResource), which means a new row is stamped with the panel's
 * tenant here rather than by Filament. A row created without one would join
 * the catalogue and be offered to every other tenant.
 */
class ListTaxCodes extends ListRecords
{
    #[\Override]
    protected static string $resource = TaxCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('panel.tax_codes.create'))
                ->icon(Heroicon::OutlinedPlus)
                ->mutateDataUsing(fn (array $data): array => TaxCodeForm::storeValue($data))
                ->using(function (array $data): TaxCode {
                    $tenant = Filament::getTenant();

                    $code = new TaxCode($data);

                    $code->forceFill([
                        'tenant_id' => $tenant instanceof Model ? $tenant->getKey() : null,
                    ])->save();

                    return $code;
                }),
        ];
    }
}
