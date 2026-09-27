<?php

namespace App\Filament\Platform\Resources\Tenants\Pages;

use App\Filament\Platform\Resources\Tenants\TenantResource;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;

/**
 * @extends EditRecord<Tenant>
 */
class EditTenant extends EditRecord
{
    #[\Override]
    protected static string $resource = TenantResource::class;

    /**
     * Save and Cancel ride at the foot of the screen while the form is longer
     * than it, so a phone never has to scroll back down to find them.
     */
    #[\Override]
    public static bool $formActionsAreSticky = true;

    #[\Override]
    public static string|Alignment $formActionsAlignment = Alignment::End;

    /**
     * The tenant itself, rather than the page's name for it, so the page says
     * whose it is as soon as it opens.
     */
    public function getHeading(): string
    {
        return $this->getRecord()->name;
    }

    /**
     * Where it is served from, what kind of business it is, and whether its
     * storefront is up.
     */
    public function getSubheading(): string
    {
        $tenant = $this->getRecord();

        return implode(' · ', [
            $tenant->slug.'.'.config('app.domain'),
            $tenant->type->label(),
            $tenant->is_active ? 'Open for business' : 'Storefront offline',
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            // The tenant menu is off in the tenant panel, so this is how an
            // admin supporting one tenant gets into it (TenantsTable does the
            // same from the list).
            Action::make('openDashboard')
                ->label('Open dashboard')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->iconButton()
                ->tooltip('Open dashboard')
                ->color('gray')
                ->url(fn (): string => $this->getRecord()->signInUrl())
                ->openUrlInNewTab(),

            DeleteAction::make()
                ->label('Delete tenant')
                ->icon(Heroicon::OutlinedTrash)
                ->iconButton()
                ->tooltip('Delete tenant'),
        ];
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->icon(Heroicon::OutlinedCheck);
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()->icon(Heroicon::OutlinedXMark);
    }
}
