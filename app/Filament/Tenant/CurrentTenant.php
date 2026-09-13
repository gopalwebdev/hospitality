<?php

namespace App\Filament\Tenant;

use App\Enums\TenantType;
use App\Models\Tenant;
use Filament\Facades\Filament;

/**
 * The tenant the tenant panel is serving, as the panel's copy needs it.
 *
 * Labels, helper text and modal headings are written in the tenant's own
 * words — "This hotel already has a menu with that name" — so each asks here
 * rather than resolving Filament's tenant and checking its class itself.
 */
class CurrentTenant
{
    /**
     * What a sentence calls the tenant being served: "hotel", "restaurant".
     *
     * Every type at once ("hotel or restaurant") where no tenant is known — a
     * schema built outside a tenant request, say — so the sentence still reads.
     */
    public static function noun(): string
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Tenant ? $tenant->type->noun() : TenantType::anyNoun();
    }
}
