<?php

namespace App\Filament\Restaurant\Resources\MenuItems\Pages;

use App\Filament\Restaurant\Resources\MenuItems\MenuItemResource;
use App\Filament\Restaurant\Resources\MenuItems\Schemas\MenuItemForm;
use App\Models\MenuCategory;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListMenuItems extends ListRecords
{
    protected static string $resource = MenuItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('panel.items.create'))
                ->icon(Heroicon::OutlinedPlus)
                // A dish has to go in a section, and a section on a menu, so
                // there is nothing useful to do until one exists. Saying so
                // beats an empty select.
                ->disabled(fn (): bool => ! $this->hasAnyCategory())
                ->tooltip(fn (): ?string => $this->hasAnyCategory() ? null : $this->needsASectionTooltip())
                ->mutateDataUsing(fn (array $data): array => MenuItemForm::storePricing($data)),
        ];
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
     * Whether this restaurant has anywhere to put a dish yet.
     */
    private function hasAnyCategory(): bool
    {
        // Asked by the button's disabled state and by its tooltip.
        return once(fn (): bool => MenuCategory::query()
            ->where('tenant_id', Filament::getTenant()?->getKey())
            ->exists());
    }
}
