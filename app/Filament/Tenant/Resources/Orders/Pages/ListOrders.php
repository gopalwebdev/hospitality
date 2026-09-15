<?php

namespace App\Filament\Tenant\Resources\Orders\Pages;

use App\Filament\Tenant\Resources\Orders\OrderResource;
use Filament\Resources\Pages\ListRecords;

class ListOrders extends ListRecords
{
    #[\Override]
    protected static string $resource = OrderResource::class;
}
