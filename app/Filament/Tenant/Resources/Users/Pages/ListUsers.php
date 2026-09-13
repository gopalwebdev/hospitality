<?php

namespace App\Filament\Restaurant\Resources\Users\Pages;

use App\Enums\Role as RoleEnum;
use App\Filament\Restaurant\Resources\Users\UserResource;
use App\Models\Restaurant;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    /**
     * How much room is left under this restaurant's admin and staff limits,
     * so nobody has to open the create form to find out it will be refused.
     */
    public function getSubheading(): ?string
    {
        $restaurant = Filament::getTenant();

        if (! $restaurant instanceof Restaurant) {
            return null;
        }

        return sprintf(
            '%d of %d admins · %d of %d staff',
            $restaurant->roleHolderCount(RoleEnum::Admin),
            $restaurant->max_admins,
            $restaurant->roleHolderCount(RoleEnum::Staff),
            $restaurant->max_staff,
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add someone')
                ->icon(Heroicon::OutlinedUserPlus),
        ];
    }
}
