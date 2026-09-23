<?php

namespace App\Filament\Tenant\Resources\Payments\Pages;

use App\Filament\Tenant\Resources\Payments\PaymentResource;
use App\Filament\Tenant\Resources\Payments\Tables\PaymentsTable;
use Filament\Resources\Pages\ViewRecord;

class ViewPayment extends ViewRecord
{
    #[\Override]
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PaymentsTable::voidAction(),
        ];
    }
}
