<?php

namespace App\Filament\Platform\Resources\Restaurants\Pages;

use App\Filament\Platform\Resources\Restaurants\RestaurantResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRestaurant extends EditRecord
{
    protected static string $resource = RestaurantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
