<?php

namespace App\Filament\Tenant\Resources\Payments\Pages;

use App\Filament\Tenant\Resources\Payments\PaymentResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Every payment this tenant has recorded, newest first — what was taken
 * today, by what method, on which machine.
 */
class ListPayments extends ListRecords
{
    #[\Override]
    protected static string $resource = PaymentResource::class;
}
