<?php

namespace App\Filament\Platform\Resources\Tenants\Pages;

use App\Enums\Role;
use App\Filament\Platform\Resources\Tenants\TenantResource;
use App\Filament\Platform\Resources\Users\UserResource;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;

/**
 * Onboarding a tenant, and then the one account that runs it.
 *
 * A tenant with nobody on its roster cannot be opened by anyone, so
 * creating one leads straight into creating its administrator rather than
 * back to the list. The account itself is made on the Users page, which holds
 * the one-time code confirmation that authorises opening an account at all —
 * minting an owner from here would go around that.
 */
class CreateTenant extends CreateRecord
{
    #[\Override]
    protected static string $resource = TenantResource::class;

    /**
     * Creating another tenant before this one has an owner is how a
     * tenant nobody can open gets left behind.
     */
    #[\Override]
    protected static bool $canCreateAnother = false;

    /**
     * Create and Cancel ride at the foot of the screen while the form is
     * longer than it, so a phone never has to scroll back down to find them.
     */
    #[\Override]
    public static bool $formActionsAreSticky = true;

    #[\Override]
    public static string|Alignment $formActionsAlignment = Alignment::End;

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Tenant created';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->icon(Heroicon::OutlinedPlus);
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()->icon(Heroicon::OutlinedXMark);
    }

    /**
     * Hand the new tenant to the account form, already chosen.
     */
    protected function getRedirectUrl(): string
    {
        $tenant = $this->getRecord();

        if (! $tenant instanceof Tenant) {
            return $this->getResource()::getUrl('index');
        }

        Notification::make()
            ->title('Now add its administrator')
            ->body(sprintf('%s has no accounts yet. This form opens with the tenant and the %s role already chosen.', $tenant->name, Role::Owner->value))
            ->info()
            ->send();

        return UserResource::getUrl('create', [
            'tenant_id' => $tenant->getKey(),
            'role' => Role::Owner->value,
        ]);
    }
}
