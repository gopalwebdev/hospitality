<?php

namespace App\Filament\Tenant\Resources\Menus\RelationManagers;

use App\Filament\Schemas\PricingFields;
use App\Filament\Tables\Reordering;
use App\Filament\Tenant\Resources\MenuItems\Schemas\MenuItemForm;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * The items a menu opens with, in the modal its Featured items row and button open on the menu page.
 *
 * Featuring is a flag on an item rather than a row of its own, so nothing is
 * created or deleted here. Items are featured from the rest of this menu, taken
 * off again, edited in place, and dragged into the order a guest reads them —
 * `featured_position`, which is separate from the `position` that orders an item
 * inside its category. The item form's Featured toggle writes the same flag
 * (.ai/rules/actions-menus.md).
 *
 * The relationship is Menu::menuItems(), which reaches items through their
 * categories because that is the only path there is, and the featured filter is
 * applied to the table's own query. See reorderTable() for what that join costs.
 */
class FeaturedItemsRelationManager extends RelationManager
{
    #[\Override]
    protected static string $relationship = 'menuItems';

    #[\Override]
    protected static ?string $recordTitleAttribute = 'name';

    #[\Override]
    protected static function getModelLabel(): ?string
    {
        return (string) __('panel.items.label');
    }

    public function form(Schema $schema): Schema
    {
        return MenuItemForm::configure($schema);
    }

    /**
     * Reorder against menu_items itself rather than through the relationship.
     *
     * Filament reorders by running one UPDATE over `$table->getQuery()`, keyed
     * on the model's unqualified `id`. This table's query is Menu::menuItems(),
     * a HasManyThrough, so it carries a join to menu_categories — and both the
     * `where in (id, ...)` and the `case when id = ...` it builds become an
     * ambiguous column reference to "id" on Postgres. Dragging the featured
     * items 500s without this.
     *
     * So the same update is issued against menu_items on its own, with the menu
     * named as a plain subquery instead of a join. The reorderable check stays
     * first and stays exactly what it was: it is the reorder() policy method,
     * and it is the only thing keeping the drag away from someone who may only
     * read the menu (.ai/rules/tables.md).
     *
     * @param  array<int|string>  $order
     */
    public function reorderTable(array $order, int|string|null $draggedRecordKey = null): void
    {
        if (! $this->getTable()->isReorderable()) {
            return;
        }

        $this->getTable()->callBeforeReordering($order);

        $connection = MenuItem::query()->getModel()->getConnection();

        MenuItem::query()
            ->whereIn('menu_items.id', array_values($order))
            ->where('is_featured', true)
            // The boundary the joined query gave for free, restated: only this
            // menu's own featured items move.
            ->whereIn('menu_category_id', MenuCategory::query()
                ->select('id')
                ->where('menu_id', $this->menu()->getKey()))
            ->update([
                'featured_position' => $this->makeTableReorderColumnExpression(
                    $order,
                    $connection->getQueryGrammar()->wrap('menu_items.id'),
                    $connection,
                ),
            ]);

        $this->getTable()->callAfterReordering($order);
    }

    public function table(Table $table): Table
    {
        return $table
            // The modal around the table is already headed "Featured items".
            ->heading(null)
            ->columns([
                ...CategoryItemsRelationManager::columns(PricingFields::currency()),

                // The branch, not just the leaf: this list spans the whole menu.
                TextColumn::make('menuCategory.name')
                    ->label(__('panel.items.section'))
                    ->formatStateUsing(fn (MenuItem $record): string => $record->menuCategory->path())
                    ->icon(Heroicon::OutlinedRectangleStack)
                    ->badge()
                    ->color('gray'),
            ])
            ->headerActions([
                $this->featureItemsAction(),
            ])
            ->recordActions([
                CategoryItemsRelationManager::editAction(),

                Action::make('unfeature')
                    ->label(__('panel.arrangement.unfeature'))
                    ->iconButton()
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('warning')
                    ->tooltip(__('panel.arrangement.unfeature'))
                    ->authorize('update')
                    // Off the featured items and nothing else: the item stays on
                    // the menu, under its own category.
                    ->action(function (MenuItem $record): void {
                        $record->update(['is_featured' => false, 'featured_position' => 0]);

                        Notification::make()->title(__('panel.arrangement.unfeatured'))->success()->send();
                    }),
            ])
            ->reorderable('featured_position')
            ->reorderRecordsTriggerAction(Reordering::trigger())
            ->defaultSort('featured_position')
            ->paginated(false)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->where('is_featured', true)
                ->with(['menuCategory:id,parent_id,name', 'menuCategory.parent:id,name']))
            ->emptyStateHeading(__('panel.items.featured_empty_heading'))
            ->emptyStateDescription(__('panel.items.featured_empty_description'))
            ->emptyStateIcon(Heroicon::OutlinedStar);
    }

    /**
     * Put some of this menu's items at the end of what it leads with, in the order they were picked.
     *
     * The Featured toggle on the item form does the same to one item. Both write
     * `is_featured`, and MenuItemObserver keeps an item that leaves the menu from
     * staying on it.
     */
    private function featureItemsAction(): Action
    {
        $menuId = $this->menu()->getKey();

        return Action::make('featureItems')
            ->label(__('panel.arrangement.feature_items'))
            ->icon(Heroicon::OutlinedStar)
            ->modalHeading(__('panel.arrangement.feature_items'))
            ->modalSubmitActionLabel(__('panel.arrangement.feature'))
            // Featuring changes items, which is menu.manage. Asked once for the
            // button; each item is checked against its own policy as it is written.
            ->visible(fn (): bool => Gate::allows('create', MenuItem::class))
            ->schema([
                Select::make('items')
                    ->label(__('panel.arrangement.items_to_feature'))
                    ->options(fn (): array => $this->unfeaturedItemOptions($menuId))
                    ->multiple()
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data) use ($menuId): void {
                $chosen = array_map(intval(...), $data['items']);

                $items = MenuItem::query()
                    ->onMenu($menuId)
                    ->where('is_featured', false)
                    ->whereKey($chosen)
                    ->get()
                    ->sortBy(fn (MenuItem $item): int => (int) array_search($item->getKey(), $chosen, true));

                $last = MenuItem::query()->featuredOnMenu($menuId)->max('featured_position');
                $position = $last === null ? 0 : ((int) $last) + 1;

                foreach ($items as $item) {
                    Gate::authorize('update', $item);

                    $item->update(['is_featured' => true, 'featured_position' => $position++]);
                }

                Notification::make()->title(__('panel.arrangement.featured'))->success()->send();
            });
    }

    /**
     * This menu's items that are not featured yet, labelled with where each is filed.
     *
     * @return array<int, string>
     */
    private function unfeaturedItemOptions(int $menuId): array
    {
        // once(): Filament asks a select for its options more than once while
        // it builds and validates one form.
        return once(fn (): array => MenuItem::query()
            ->onMenu($menuId)
            ->where('is_featured', false)
            ->with(['menuCategory:id,parent_id,name', 'menuCategory.parent:id,name'])
            ->inMenuOrder()
            ->get()
            ->mapWithKeys(fn (MenuItem $item): array => [
                $item->getKey() => sprintf('%s · %s', $item->menuCategory->path(), $item->name),
            ])
            ->all());
    }

    private function menu(): Menu
    {
        $menu = $this->getOwnerRecord();

        return $menu instanceof Menu ? $menu : throw new LogicException('The featured items table requires a menu.');
    }
}
