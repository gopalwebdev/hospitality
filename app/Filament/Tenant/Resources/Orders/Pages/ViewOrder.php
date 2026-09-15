<?php

namespace App\Filament\Tenant\Resources\Orders\Pages;

use App\Filament\Tenant\Resources\Orders\OrderResource;
use App\Filament\Tenant\Resources\Orders\Tables\OrdersTable;
use Filament\Resources\Pages\ViewRecord;

class ViewOrder extends ViewRecord
{
    #[\Override]
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            OrdersTable::cancelAction(),
        ];
    }
}
