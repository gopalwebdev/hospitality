<?php

namespace App\Filament\Platform\Resources\Restaurants\Pages;

use App\Filament\Platform\Resources\Restaurants\RestaurantResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRestaurants extends ListRecords
{
    protected static string $resource = RestaurantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
