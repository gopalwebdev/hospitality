<?php

namespace App\Filament\Tenant\Resources\MenuItems\Pages;

use App\Filament\Tenant\Resources\MenuItems\MenuItemResource;
use App\Filament\Tenant\Resources\MenuItems\Schemas\MenuItemForm;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListMenuItems extends ListRecords
{
    #[\Override]
    protected static string $resource = MenuItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('panel.items.create'))
                ->icon(Heroicon::OutlinedPlus)
                // An item has to go in a section, and a section on a menu, so
                // there is nothing useful to do until one exists. Saying so
                // beats an empty select.
                ->disabled(fn (): bool => ! $this->hasAnyCategory())
                ->tooltip(fn (): ?string => $this->hasAnyCategory() ? null : $this->needsASectionTooltip())
                ->mutateDataUsing(fn (array $data): array => MenuItemForm::storePricing($data)),
        ];
    }

    /**
     * Things to order and service requests are one table and two jobs, so each has a tab.
     *
     * The counts load after the page has rendered, from one grouped query.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('panel.items.all_tab'))
                ->icon(Heroicon::OutlinedRectangleStack)
                ->badge(fn (): int => array_sum($this->countsByKind()))
                ->deferBadge(),

            'items' => Tab::make(__('panel.items.items_tab'))
                ->icon(Heroicon::OutlinedListBullet)
                ->badge(fn (): int => $this->countsByKind()['items'])
                ->deferBadge()
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_service_request', false)),

            'service_requests' => Tab::make(__('panel.items.service_requests_tab'))
                ->icon(Heroicon::OutlinedBellAlert)
                ->badge(fn (): int => $this->countsByKind()['service_requests'])
                ->deferBadge()
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_service_request', true)),
        ];
    }

    /**
     * How many of this tenant's items are service requests, and how many are not.
     *
     * One query for all three badges; the panel's tenancy scope keeps it to this
     * tenant's items.
     *
     * @return array{items: int, service_requests: int}
     */
    private function countsByKind(): array
    {
        return once(function (): array {
            $counts = MenuItem::query()
                ->toBase()
                ->selectRaw('is_service_request, count(*) as aggregate')
                ->groupBy('is_service_request')
                ->pluck('aggregate', 'is_service_request');

            return [
                'items' => (int) ($counts[0] ?? 0),
                'service_requests' => (int) ($counts[1] ?? 0),
            ];
        });
    }

    /**
     * Why the button is disabled, in the language the panel is being worked in.
     *
     * `__()` is typed as string|array|null because a key may hold either, so
     * the one call site with a declared return type checks rather than casts.
     */
    private function needsASectionTooltip(): ?string
    {
        $tooltip = __('panel.items.needs_a_section');

        return is_string($tooltip) ? $tooltip : null;
    }

    /**
     * Whether this tenant has anywhere to put an item yet.
     */
    private function hasAnyCategory(): bool
    {
        // Asked by the button's disabled state and by its tooltip.
        return once(fn (): bool => MenuCategory::query()
            ->where('tenant_id', Filament::getTenant()?->getKey())
            ->exists());
    }
}
