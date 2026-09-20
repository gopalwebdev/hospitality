<?php

namespace App\Filament\Tenant\Resources\MenuItems\Pages;

use App\Enums\Diet;
use App\Enums\ItemAvailability;
use App\Enums\MenuItemKind;
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
     * Consumables, Goods and Services, then the cuts the list is most often made by: what has run out, what is featured, and each diet mark.
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

            'consumables' => Tab::make(__('panel.items.consumables_tab'))
                ->icon(MenuItemKind::Consumable->icon())
                ->badge(fn (): int => $this->counts()['consumables'])
                ->deferBadge()
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('kind', MenuItemKind::Consumable)),

            'goods' => Tab::make(__('panel.items.goods_tab'))
                ->icon(MenuItemKind::Goods->icon())
                ->badge(fn (): int => $this->counts()['goods'])
                ->deferBadge()
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('kind', MenuItemKind::Goods)),

            'services' => Tab::make(__('panel.items.services_tab'))
                ->icon(MenuItemKind::Service->icon())
                ->badge(fn (): int => $this->counts()['services'])
                ->deferBadge()
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('kind', MenuItemKind::Service)),

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
            'vegan' => $this->dietTab(Diet::Vegan, 'vegan', __('panel.items.vegan_tab')),
            'egg' => $this->dietTab(Diet::Egg, 'egg', __('panel.items.egg_tab')),
            'non_veg' => $this->dietTab(Diet::NonVegetarian, 'non_veg', __('panel.items.non_veg_tab')),
        ];
    }

    /**
     * One diet mark's tab, its badge in the mark's own colour.
     *
     * An item carries a list of marks, so a tab asks whether its own is among
     * them: an item that is vegetarian and vegan is on both tabs, which is the
     * point of being able to say both.
     */
    private function dietTab(Diet $diet, string $key, mixed $label): Tab
    {
        return Tab::make(is_string($label) ? $label : $diet->label())
            ->badge(fn (): int => $this->counts()[$key])
            ->badgeColor($diet->color())
            ->deferBadge()
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereJsonContains('diets', $diet->value));
    }

    /**
     * One mark, shaped as the jsonb array `diets @> ?` is asked with.
     */
    private function carrying(Diet $diet): string
    {
        return (string) json_encode([$diet->value]);
    }

    /**
     * How many of this tenant's items each tab holds.
     *
     * One query of conditional counts for every badge; the panel's tenancy
     * scope keeps it to this tenant's items. The diet counts overlap on
     * purpose: an item marked vegetarian and vegan is counted under both.
     *
     * @return array{all: int, consumables: int, goods: int, services: int, out_of_stock: int, featured: int, veg: int, vegan: int, egg: int, non_veg: int}
     */
    private function counts(): array
    {
        return once(function (): array {
            $row = MenuItem::query()
                ->toBase()
                ->selectRaw(
                    'count(*) as all_items'
                    .', sum(case when kind = ? then 1 else 0 end) as consumables'
                    .', sum(case when kind = ? then 1 else 0 end) as goods'
                    .', sum(case when kind = ? then 1 else 0 end) as services'
                    .', sum(case when availability = ? then 1 else 0 end) as out_of_stock'
                    .', sum(case when is_featured then 1 else 0 end) as featured'
                    .', sum(case when diets @> ?::jsonb then 1 else 0 end) as veg'
                    .', sum(case when diets @> ?::jsonb then 1 else 0 end) as vegan'
                    .', sum(case when diets @> ?::jsonb then 1 else 0 end) as egg'
                    .', sum(case when diets @> ?::jsonb then 1 else 0 end) as non_veg',
                    [
                        MenuItemKind::Consumable->value,
                        MenuItemKind::Goods->value,
                        MenuItemKind::Service->value,
                        ItemAvailability::OutOfStock->value,
                        $this->carrying(Diet::Vegetarian),
                        $this->carrying(Diet::Vegan),
                        $this->carrying(Diet::Egg),
                        $this->carrying(Diet::NonVegetarian),
                    ],
                )
                ->first();

            return [
                'all' => (int) ($row->all_items ?? 0),
                'consumables' => (int) ($row->consumables ?? 0),
                'goods' => (int) ($row->goods ?? 0),
                'services' => (int) ($row->services ?? 0),
                'out_of_stock' => (int) ($row->out_of_stock ?? 0),
                'featured' => (int) ($row->featured ?? 0),
                'veg' => (int) ($row->veg ?? 0),
                'vegan' => (int) ($row->vegan ?? 0),
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
