<?php

namespace App\Filament\Tenant\Resources\MenuItems\Tables;

use App\Enums\Diet;
use App\Enums\ItemAvailability;
use App\Filament\Schemas\PricingFields;
use App\Filament\Schemas\TranslatedFields;
use App\Filament\Tables\StockActions;
use App\Filament\Tenant\Resources\MenuItems\Schemas\MenuItemForm;
use App\Filament\Tenant\Resources\Menus\Schemas\MenuCategoryForm;
use App\Filament\Tenant\Resources\Menus\Schemas\MenuSubCategoryForm;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Tenant;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MenuItemsTable
{
    public static function configure(Table $table): Table
    {
        // Resolved once for the page rather than per row: every item here
        // belongs to the same tenant and shares its currency.
        $currency = PricingFields::currency();
        $complimentary = (string) __('panel.items.complimentary');

        return $table
            // No column says whether an item is a service request: the tabs
            // above the table do (ListMenuItems::getTabs()).
            ->columns([
                TextColumn::make('name')
                    // A translated column holds a JSON document, so searching
                    // and sorting have to name the language they mean.
                    ->searchable(query: fn (Builder $query, string $search): Builder => TranslatedFields::search($query, 'name', $search))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => TranslatedFields::sort($query, 'name', $direction))
                    ->description(fn (MenuItem $record): ?string => $record->description),

                TextColumn::make('menuCategory.name')
                    ->label(__('panel.items.section'))
                    ->icon(Heroicon::OutlinedRectangleStack)
                    // The branch, not just the leaf: a subdivision on its own
                    // says nothing about which section it belongs to.
                    ->formatStateUsing(fn (MenuItem $record): string => $record->menuCategory->path())
                    ->badge()
                    ->color('gray'),

                TextColumn::make('menuCategory.menu.name')
                    ->label(__('panel.categories.menu'))
                    ->icon(Heroicon::OutlinedBookOpen)
                    ->badge()
                    ->color('gray')
                    ->toggleable(),

                // One badge per mark, so an item that is vegetarian and vegan
                // says both here — unlike the guest menu, which shows the
                // strictest alone. Empty for a service request, which carries
                // no mark at all.
                TextColumn::make('diets')
                    ->label(__('panel.items.diet'))
                    ->badge()
                    ->formatStateUsing(fn (Diet $state): string => $state->label())
                    ->color(fn (Diet $state): string => $state->color())
                    ->placeholder('—'),

                // Stored in minor units, shown as money in the tenant's own
                // currency. Sorting works on the integer, which is the point of
                // storing it that way.
                //
                // Formatted here rather than in the browser, unlike the guest
                // app: a panel is server rendered, and the currency
                // is resolved once for the page rather than per row.
                TextColumn::make('price')
                    ->label(__('panel.items.price'))
                    ->formatStateUsing(fn (MenuItem $record): string => $record->isComplimentary()
                        ? $complimentary
                        : $record->formattedPrice($currency))
                    // The struck-through price rides under the real one rather
                    // than taking a column of its own, which would be empty for
                    // every item that is not on offer — most of them.
                    ->description(fn (MenuItem $record): ?string => $record->formattedComparePrice($currency))
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('add_on_group_links_count')
                    ->label(__('panel.add_on_groups.plural'))
                    ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                    ->counts('addOnGroupLinks')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('availability')
                    ->label(__('panel.items.availability'))
                    ->badge()
                    ->formatStateUsing(fn (ItemAvailability $state): string => $state->label())
                    ->color(fn (ItemAvailability $state): string => $state->color())
                    ->sortable(),

                // A dash when nobody counts it, red at none left.
                TextColumn::make('stock_quantity')
                    ->label(__('panel.stock.in_stock'))
                    ->numeric()
                    ->placeholder('—')
                    ->color(fn (?int $state): ?string => $state === 0 ? 'danger' : null)
                    ->sortable()
                    ->alignEnd(),
            ])
            ->filters([
                // An item reaches its menu through its category, so this filters
                // on the relationship rather than on a column of its own.
                SelectFilter::make('menu')
                    ->label(__('panel.categories.menu'))
                    ->options(fn (): array => MenuCategoryForm::menuOptions())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $onMenu): Builder => $onMenu->whereRelation(
                            'menuCategory',
                            'menu_id',
                            $data['value'],
                        ),
                    )),

                // A category brings its sub-categories' items with it: picking
                // Starters asks for everything under Starters. Narrowed to the
                // chosen menu, so picking a menu and then a category reads as one
                // decision rather than two lists repeating each other.
                SelectFilter::make('category')
                    ->label(__('panel.categories.section'))
                    ->options(fn (HasTable $livewire): array => MenuSubCategoryForm::topLevelOptions(
                        self::tenantKey(),
                        self::filterValue($livewire, 'menu'),
                    ))
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $inBranch): Builder => $inBranch->whereIn(
                            'menu_category_id',
                            MenuCategory::query()
                                ->select('id')
                                ->where(fn (Builder $branch): Builder => $branch
                                    ->whereKey((int) $data['value'])
                                    ->orWhere('parent_id', (int) $data['value'])),
                        ),
                    )),

                // Narrowed to the category picked beside it, else to the menu.
                SelectFilter::make('sub_category')
                    ->label(__('panel.sub_categories.section'))
                    ->options(fn (HasTable $livewire): array => MenuSubCategoryForm::subCategoryOptions(
                        self::tenantKey(),
                        self::filterValue($livewire, 'menu'),
                        self::filterValue($livewire, 'category'),
                    ))
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $inSubCategory): Builder => $inSubCategory->where('menu_category_id', (int) $data['value']),
                    )),

                // The marks are a list, so the filter asks whether the item
                // carries the one picked rather than whether it equals it.
                SelectFilter::make('diets')
                    ->label(__('panel.items.diet'))
                    ->options(Diet::options())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $carrying): Builder => $carrying->whereJsonContains('diets', $data['value']),
                    )),

                SelectFilter::make('availability')
                    ->label(__('panel.items.availability'))
                    ->options(ItemAvailability::options()),

                Filter::make('tracks_stock')
                    ->label(__('panel.stock.tracked'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('stock_quantity')),

                Filter::make('on_offer')
                    ->label(__('panel.items.on_offer'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('compare_at_price')),
            ])
            // Behind the table's filter button rather than laid out above it,
            // where they took half the screen before the first row. The cuts
            // made most often — service requests, what has run out, what is
            // featured, each diet mark — are the tabs instead
            // (ListMenuItems::getTabs()).
            ->filtersFormColumns(2)
            ->filtersFormWidth(Width::ThreeExtraLarge)
            ->recordActions([
                StockActions::adjust(),
                StockActions::history(),

                EditAction::make()
                    ->iconButton()
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->tooltip(__('panel.arrangement.edit'))
                    // The item form is laid out in columns for the whole width.
                    ->modalWidth(Width::Full)
                    ->mutateRecordDataUsing(fn (array $data, MenuItem $record): array => MenuItemForm::fill($data, $record))
                    ->using(fn (array $data, MenuItem $record): MenuItem => MenuItemForm::update($record, $data)),

                DeleteAction::make()
                    ->iconButton()
                    ->icon(Heroicon::OutlinedTrash)
                    ->tooltip(__('panel.arrangement.delete'))
                    ->modalDescription(__('panel.items.delete_warning')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            // Items are not dragged here. This page is a flat list of every
            // item on every menu, and an item's position is only ever read
            // within its own category — so a drag here would rewrite a number
            // that meant nothing where it landed. Items are put in order in
            // the table their category's row opens on the menu's arrangement
            // page, which is the only place the order is legible anyway.
            //
            // Menu, then section, then the order the tenant dragged the
            // items into. Filament's grouping used to imply this; with the
            // group gone the query has to say it.
            ->defaultSort(fn (Builder $query): Builder => self::inMenuOrder($query))
            // The category, its parent and its menu are all read per row for
            // the tree headings, so all three are loaded once for the page.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['menuCategory.menu', 'menuCategory.parent']));
    }

    /**
     * What a filter is set to, as the key it names, or null while it is not set.
     */
    private static function filterValue(HasTable $livewire, string $filter): ?int
    {
        $value = $livewire->getTableFilterState($filter)['value'] ?? null;

        return filled($value) ? (int) $value : null;
    }

    /**
     * The tenant the panel is serving.
     */
    private static function tenantKey(): ?int
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Tenant ? $tenant->getKey() : null;
    }

    /**
     * Read the list the way a guest reads the menu.
     *
     * Menu, oldest first — menus are not put in order by hand, and a name
     * would be a translated column (.ai/rules/tables.md) — then the section an
     * item sits under, then a section's own items before its subdivisions',
     * then the order they were dragged into. Grouping used to imply most of
     * this; with the group gone the query says it.
     *
     * Raw because each rank is a correlated subquery over menu_categories,
     * which appears twice — once as the item's own category and once as that
     * category's parent. Every rank is COALESCEd rather than left null, so an
     * item filed straight under a category and one inside a subdivision rank
     * against each other by value rather than by where Postgres puts a null.
     *
     * @param  Builder<MenuItem>  $query
     * @return Builder<MenuItem>
     */
    private static function inMenuOrder(Builder $query): Builder
    {
        return $query
            ->orderByRaw('(select menu_id from menu_categories where id = menu_items.menu_category_id)')
            ->orderByRaw(
                'coalesce('
                .'(select parents.position from menu_categories parents'
                .' join menu_categories own on own.parent_id = parents.id'
                .' where own.id = menu_items.menu_category_id), '
                .'(select position from menu_categories where id = menu_items.menu_category_id)'
                .')'
            )
            ->orderByRaw(
                'coalesce((select case when parent_id is null then -1 else position end'
                .' from menu_categories where id = menu_items.menu_category_id), -1)'
            )
            ->orderBy('position');
    }
}
