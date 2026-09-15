<?php

namespace App\Filament\Tenant\Resources\MenuItems\Pages;

use App\Enums\Diet;
use App\Enums\ItemAvailability;
use App\Filament\Tenant\Resources\MenuItems\MenuItemResource;
use App\Filament\Tenant\Resources\MenuItems\Schemas\MenuItemForm;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Enums\Width;
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
                // The item form is laid out in columns for the whole width.
                ->modalWidth(Width::Full)
                // An item has to go in a section, and a section on a menu, so
                // there is nothing useful to do until one exists. Saying so
                // beats an empty select.
                ->disabled(fn (): bool => ! $this->hasAnyCategory())
                ->tooltip(fn (): ?string => $this->hasAnyCategory() ? null : $this->needsASectionTooltip())
                ->mutateDataUsing(fn (array $data): array => MenuItemForm::storeNew($data)),
        ];
    }

    /**
     * Things to order and service requests, then the cuts the list is most often made by: what has run out, what is featured, and each diet mark.
     *
     * The project owner asked for Out of stock, Featured and the diet marks
     * beside the kind rather than behind the filter button; Featured replaced
     * a column and a filter. A tab combines with whatever
     * filters are set. Every badge loads after the page has rendered, from one
     * query.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('panel.items.all_tab'))
                ->icon(Heroicon::OutlinedRectangleStack)
                ->badge(fn (): int => $this->counts()['all'])
                ->deferBadge(),

            'items' => Tab::make(__('panel.items.items_tab'))
                ->icon(Heroicon::OutlinedListBullet)
                ->badge(fn (): int => $this->counts()['items'])
                ->deferBadge()
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_service_request', false)),

            'service_requests' => Tab::make(__('panel.items.service_requests_tab'))
                ->icon(Heroicon::OutlinedBellAlert)
                ->badge(fn (): int => $this->counts()['service_requests'])
                ->deferBadge()
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_service_request', true)),

            'out_of_stock' => Tab::make(__('panel.items.out_of_stock_tab'))
                ->icon(Heroicon::OutlinedNoSymbol)
                ->badge(fn (): int => $this->counts()['out_of_stock'])
                ->badgeColor(ItemAvailability::OutOfStock->color())
                ->deferBadge()
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('availability', ItemAvailability::OutOfStock->value)),

            'featured' => Tab::make(__('panel.items.featured_tab'))
                ->icon(Heroicon::OutlinedStar)
                ->badge(fn (): int => $this->counts()['featured'])
                ->badgeColor('warning')
                ->deferBadge()
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_featured', true)),

            'veg' => $this->dietTab(Diet::Vegetarian, 'veg', __('panel.items.veg_tab')),
            'egg' => $this->dietTab(Diet::Egg, 'egg', __('panel.items.egg_tab')),
            'non_veg' => $this->dietTab(Diet::NonVegetarian, 'non_veg', __('panel.items.non_veg_tab')),
        ];
    }

    /**
     * One diet mark's tab, its badge in the mark's own colour.
     */
    private function dietTab(Diet $diet, string $key, mixed $label): Tab
    {
        return Tab::make(is_string($label) ? $label : $diet->label())
            ->badge(fn (): int => $this->counts()[$key])
            ->badgeColor($diet->color())
            ->deferBadge()
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('diet', $diet->value));
    }

    /**
     * How many of this tenant's items each tab holds.
     *
     * One query of conditional counts for every badge; the panel's tenancy
     * scope keeps it to this tenant's items.
     *
     * @return array{all: int, items: int, service_requests: int, out_of_stock: int, featured: int, veg: int, egg: int, non_veg: int}
     */
    private function counts(): array
    {
        return once(function (): array {
            $row = MenuItem::query()
                ->toBase()
                ->selectRaw(
                    'count(*) as all_items'
                    .', sum(case when is_service_request then 0 else 1 end) as items'
                    .', sum(case when is_service_request then 1 else 0 end) as service_requests'
                    .', sum(case when availability = ? then 1 else 0 end) as out_of_stock'
                    .', sum(case when is_featured then 1 else 0 end) as featured'
                    .', sum(case when diet = ? then 1 else 0 end) as veg'
                    .', sum(case when diet = ? then 1 else 0 end) as egg'
                    .', sum(case when diet = ? then 1 else 0 end) as non_veg',
                    [
                        ItemAvailability::OutOfStock->value,
                        Diet::Vegetarian->value,
                        Diet::Egg->value,
                        Diet::NonVegetarian->value,
                    ],
                )
                ->first();

            return [
                'all' => (int) ($row->all_items ?? 0),
                'items' => (int) ($row->items ?? 0),
                'service_requests' => (int) ($row->service_requests ?? 0),
                'out_of_stock' => (int) ($row->out_of_stock ?? 0),
                'featured' => (int) ($row->featured ?? 0),
                'veg' => (int) ($row->veg ?? 0),
                'egg' => (int) ($row->egg ?? 0),
                'non_veg' => (int) ($row->non_veg ?? 0),
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
