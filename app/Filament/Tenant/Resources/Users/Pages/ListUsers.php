<?php

namespace App\Filament\Tenant\Resources\Users\Pages;

use App\Enums\Role as RoleEnum;
use App\Filament\Tenant\Resources\Users\UserResource;
use App\Models\Tenant;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListUsers extends ListRecords
{
    #[\Override]
    protected static string $resource = UserResource::class;

    /**
     * How much room is left under this tenant's owner and staff limits,
     * so nobody has to open the create form to find out it will be refused.
     */
    public function getSubheading(): ?string
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Tenant) {
            return null;
        }

        return sprintf(
            '%d of %d owners · %d of %d staff',
            $tenant->roleHolderCount(RoleEnum::Owner),
            $tenant->max_owners,
            $tenant->roleHolderCount(RoleEnum::Staff),
            $tenant->max_staff,
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
