<?php

namespace App\Filament\Tenant\Resources\Menus\Tables;

use App\Actions\Menus\ApplyMenuArrangement;
use App\Actions\Menus\MoveCategoryToMenu;
use App\Enums\Currency;
use App\Enums\Locale;
use App\Enums\MenuBlockType;
use App\Filament\Schemas\PricingFields;
use App\Filament\Tables\Reordering;
use App\Filament\Tenant\Resources\MenuItems\Schemas\MenuItemForm;
use App\Filament\Tenant\Resources\Menus\Schemas\MenuCategoryForm;
use App\Filament\Tenant\Resources\Menus\Schemas\MenuComboForm;
use App\Filament\Tenant\Resources\Menus\Schemas\MenuSubCategoryForm;
use App\Models\Menu;
use App\Models\MenuBlock;
use App\Models\MenuCategory;
use App\Models\MenuCombo;
use App\Models\MenuItem;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * A whole menu as one list, in the order a guest reads it — and the one place it is edited.
 *
 * Every row a menu has: its blocks (the featured items and the combos, each with
 * what is in it listed underneath), every category, every subdivision and every
 * item. Each is added to, edited and deleted from its own row, and one drag puts
 * any of them somewhere else. A block with nothing in it is not drawn at all;
 * the buttons in the header are how one is started.
 *
 * It is built on Filament's custom data (`records()`) rather than on a query,
 * because the rows are several models plus blocks that may not be rows anywhere
 * yet. Two consequences before editing:
 *
 * - Every record is a plain array keyed by `__key`, so an action's `$record` is
 *   an array and nothing here may be typed `Model`. The model behind a row is
 *   looked up when an action runs, not while the table renders.
 * - Filament reorders by running one UPDATE over the table's Eloquent query, of
 *   which there is none. ArrangeMenu::reorderTable() overrides that and hands
 *   the dropped order to App\Actions\Menus\ApplyMenuArrangement. The
 *   `reorderable()` call still matters: it renders the handles, and its
 *   condition is what Filament checks before accepting the write.
 *
 * Items and combos are edited here too, in a slide-over, and both forms carry a
 * repeater bound to a relationship. A repeater needs a real record behind its
 * schema and a row here is an array, so each of those actions hands the schema
 * the model (`$schema->model(...)`) and saves the repeater the way Filament's own
 * CreateAction does.
 */
class MenuArrangementTable
{
    private const string BLOCK = 'block';

    private const string FEATURED_ITEM = 'featured_item';

    private const string COMBO = 'combo';

    private const string CATEGORY = 'category';

    private const string SUB_CATEGORY = 'sub_category';

    private const string ITEM = 'item';

    public static function configure(Table $table, Menu $menu): Table
    {
        // Asked once for the page rather than per row per action: every
        // mutation here is menu.manage, and the menu policies answer it without
        // looking at the record. The record *is* checked, with its real policy
        // method, at the moment an action writes — see the closures below,
        // where the model has been loaded anyway.
        $mayManage = Gate::allows('create', MenuCategory::class);

        return $table
            ->records(fn (): Collection => self::rows($menu))
            // A menu is one screen's worth of structure, and a drag has to be
            // able to carry an item at the bottom to the top of the list.
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->label(__('panel.arrangement.name'))
                    // The indentation is the tree: an item sits under the
                    // heading it belongs to and a subdivision under its
                    // category. Built as HTML so the line under a name is
                    // indented with it rather than flush against the edge.
                    ->html()
                    ->formatStateUsing(fn (array $record): string => self::nameHtml($record)),

                TextColumn::make('type')
                    ->label(__('panel.arrangement.type'))
                    ->badge()
                    ->color(fn (array $record): string => $record['type_color']),

                TextColumn::make('meta')
                    ->label(__('panel.arrangement.contents'))
                    ->alignEnd(),

                TextColumn::make('state')
                    ->label(__('panel.shared.showing'))
                    ->badge()
                    ->color(fn (array $record): string => $record['state_color'] ?? 'gray')
                    // A block is neither showing nor hidden: it is drawn when it
                    // has something in it.
                    ->placeholder(''),
            ])
            ->headerActions([
                self::createCategoryAction('createCategory', $menu)->visible($mayManage),
                self::createComboAction('createCombo', $menu)->color('gray')->visible($mayManage),
                self::featureItemsAction('featureItems', $menu)->color('gray')->visible($mayManage),
            ])
            ->recordActions([
                // What a row is mostly for sits on the row itself; everything
                // else about it is under the ⋯.
                self::createItemAction()
                    ->visible(fn (array $record): bool => $mayManage && self::isCategoryRow($record)),
                self::featureItemsAction('addFeaturedItems', $menu)
                    ->visible(fn (array $record): bool => $mayManage && self::isBlockRow($record, MenuBlockType::Featured)),
                self::createComboAction('addCombo', $menu)
                    ->visible(fn (array $record): bool => $mayManage && self::isBlockRow($record, MenuBlockType::Combos)),
                self::editItemAction($menu)
                    ->visible(fn (array $record): bool => $mayManage && self::isItemRow($record)),
                self::editComboAction($menu)
                    ->visible(fn (array $record): bool => $mayManage && $record['kind'] === self::COMBO),

                ActionGroup::make([
                    self::renameAction($menu)
                        ->visible(fn (array $record): bool => $mayManage && self::isCategoryRow($record)),
                    self::createSubCategoryAction($menu)
                        // Two levels and no more, so only a top-level category holds one.
                        ->visible(fn (array $record): bool => $mayManage && $record['kind'] === self::CATEGORY),
                    self::moveCategoryAction($menu)
                        ->visible(fn (array $record): bool => $mayManage && $record['kind'] === self::CATEGORY),
                    self::unfeatureAction($menu)
                        ->visible(fn (array $record): bool => $mayManage && $record['kind'] === self::FEATURED_ITEM),
                    self::deleteItemAction($menu)
                        ->visible(fn (array $record): bool => $mayManage && $record['kind'] === self::ITEM),
                    self::deleteComboAction($menu)
                        ->visible(fn (array $record): bool => $mayManage && $record['kind'] === self::COMBO),
                    self::deleteCategoryAction($menu)
                        ->visible(fn (array $record): bool => $mayManage && self::isCategoryRow($record)),
                ])
                    ->label(__('panel.arrangement.actions'))
                    ->icon(Heroicon::OutlinedEllipsisHorizontal)
                    ->color('gray')
                    ->visible(fn (array $record): bool => $mayManage && $record['kind'] !== self::BLOCK),
            ])
            // Clicking a row opens whatever edits it.
            ->recordAction(fn (array $record): ?string => $mayManage ? self::editActionFor($record) : null)
            // Filament renders the handles on this call and short-circuits the
            // write on it too, so the condition is the authorization: putting a
            // menu in order is changing it. See ArrangeMenu::reorderTable().
            ->reorderable('position', condition: fn (): bool => Gate::allows('reorder', MenuCategory::class))
            ->reorderRecordsTriggerAction(Reordering::trigger())
            ->emptyStateHeading(__('panel.arrangement.empty_heading'))
            ->emptyStateDescription(__('panel.arrangement.empty_description'))
            ->emptyStateIcon(Heroicon::OutlinedRectangleStack)
            ->emptyStateActions([
                self::createCategoryAction('createFirstCategory', $menu)->button()->visible($mayManage),
            ]);
    }

    /**
     * Every row of this menu, in reading order and keyed for the table.
     *
     * Four queries whatever the menu holds: its categories at both levels with
     * the items in them, its placed blocks, and its combos with a count of what
     * is in each. The featured items are picked out of the items already loaded
     * rather than asked for again.
     *
     * @return Collection<string, array<string, mixed>>
     */
    private static function rows(Menu $menu): Collection
    {
        $currency = PricingFields::currency();
        $complimentary = (string) __('panel.items.complimentary');

        $categories = MenuCategory::query()
            ->select(['id', 'parent_id', 'name', 'position', 'is_active'])
            ->where('menu_id', $menu->getKey())
            ->with(['menuItems' => fn ($items) => $items
                ->select(['id', 'menu_category_id', 'name', 'description', 'price_minor_units', 'is_service', 'availability', 'is_featured', 'featured_position', 'position'])
                ->inMenuOrder()])
            ->inMenuOrder()
            ->get();

        $blocks = MenuBlock::query()
            ->select(['id', 'menu_id', 'type', 'position'])
            ->where('menu_id', $menu->getKey())
            ->get();

        $combos = MenuCombo::query()
            ->select(['id', 'menu_id', 'name', 'description', 'price_minor_units', 'availability', 'position'])
            ->where('menu_id', $menu->getKey())
            ->withCount('comboItems')
            ->inMenuOrder()
            ->get();

        // In the featured order, which is the same as MenuItem::scopeInFeaturedOrder().
        $english = Locale::default()->value;
        $featured = $categories
            ->flatMap(fn (MenuCategory $category): array => $category->menuItems->where('is_featured', true)->all())
            ->sort(fn (MenuItem $a, MenuItem $b): int => [$a->featured_position, $a->getTranslation('name', $english)]
                <=> [$b->featured_position, $b->getTranslation('name', $english)])
            ->values();

        $subCategories = $categories
            ->filter(fn (MenuCategory $category): bool => $category->isSubCategory())
            ->groupBy('parent_id');

        $topLevel = $categories->filter(fn (MenuCategory $category): bool => $category->isTopLevel());

        $rows = [];

        foreach ($menu->readingOrder($topLevel, $blocks) as $entry) {
            if ($entry instanceof MenuBlock) {
                array_push($rows, ...self::blockRows($entry, $featured, $combos, $currency, $complimentary));

                continue;
            }

            $children = $subCategories->get($entry->getKey(), new Collection);

            $rows[] = self::categoryRow($entry, self::CATEGORY, depth: 0, subCategoryCount: $children->count());

            foreach ($entry->menuItems as $item) {
                $rows[] = self::itemRow($item, $currency, $complimentary, depth: 1);
            }

            foreach ($children as $child) {
                $rows[] = self::categoryRow($child, self::SUB_CATEGORY, depth: 1, subCategoryCount: 0);

                foreach ($child->menuItems as $item) {
                    $rows[] = self::itemRow($item, $currency, $complimentary, depth: 2);
                }
            }
        }

        return collect($rows)->keyBy('__key');
    }

    /**
     * A block's row and the rows of what is in it — or nothing at all while it is empty.
     *
     * An empty block used to be a heading with nothing under it, wherever it had
     * been placed. It is not drawn now: the header's buttons start one, and it
     * appears where it was placed as soon as it has something in it.
     *
     * @param  Collection<int, MenuItem>  $featured
     * @param  EloquentCollection<int, MenuCombo>  $combos
     * @return list<array<string, mixed>>
     */
    private static function blockRows(MenuBlock $block, Collection $featured, EloquentCollection $combos, Currency $currency, string $complimentary): array
    {
        return match ($block->type) {
            MenuBlockType::Featured => $featured->isEmpty() ? [] : [
                self::blockRow($block, trans_choice('panel.arrangement.items_count', $featured->count(), ['count' => $featured->count()])),
                ...$featured->map(fn (MenuItem $item): array => self::itemRow($item, $currency, $complimentary, depth: 1, kind: self::FEATURED_ITEM))->all(),
            ],
            MenuBlockType::Combos => $combos->isEmpty() ? [] : [
                self::blockRow($block, trans_choice('panel.arrangement.combos_count', $combos->count(), ['count' => $combos->count()])),
                ...$combos->map(fn (MenuCombo $combo): array => self::comboRow($combo, $currency))->all(),
            ],
        };
    }

    /**
     * The heading row of a block: what it is and how much is in it.
     *
     * @return array<string, mixed>
     */
    private static function blockRow(MenuBlock $block, string $meta): array
    {
        return [
            '__key' => ApplyMenuArrangement::blockKey($block),
            'kind' => self::BLOCK,
            'block' => $block->type->value,
            'id' => $block->getKey(),
            'depth' => 0,
            'name' => $block->type->label(),
            'detail' => $block->type->description(),
            'type' => __('panel.arrangement.block'),
            'type_color' => 'warning',
            'meta' => $meta,
            'state' => null,
            'state_color' => null,
        ];
    }

    /**
     * A category at either level.
     *
     * @return array<string, mixed>
     */
    private static function categoryRow(MenuCategory $category, string $kind, int $depth, int $subCategoryCount): array
    {
        $itemCount = $category->menuItems->count();
        $items = trans_choice('panel.arrangement.items_count', $itemCount, ['count' => $itemCount]);

        return [
            '__key' => ApplyMenuArrangement::categoryKey($category->getKey()),
            'kind' => $kind,
            'block' => null,
            'id' => $category->getKey(),
            'depth' => $depth,
            'name' => $category->name,
            'detail' => null,
            'type' => $kind === self::CATEGORY
                ? __('panel.categories.section')
                : __('panel.sub_categories.section'),
            'type_color' => $kind === self::CATEGORY ? 'primary' : 'info',
            'meta' => $subCategoryCount > 0
                ? $items.' · '.trans_choice('panel.arrangement.sub_categories_count', $subCategoryCount, ['count' => $subCategoryCount])
                : $items,
            'state' => $category->is_active
                ? __('panel.shared.showing')
                : __('panel.arrangement.hidden'),
            'state_color' => $category->is_active ? 'success' : 'gray',
        ];
    }

    /**
     * One item, under its category or in the featured list, labelled as an item or a service request.
     *
     * @return array<string, mixed>
     */
    private static function itemRow(MenuItem $item, Currency $currency, string $complimentary, int $depth, string $kind = self::ITEM): array
    {
        return [
            '__key' => $kind === self::FEATURED_ITEM
                ? ApplyMenuArrangement::featuredItemKey($item->getKey())
                : ApplyMenuArrangement::itemKey($item->getKey()),
            'kind' => $kind,
            'block' => null,
            'id' => $item->getKey(),
            'depth' => $depth,
            'name' => $item->name,
            'detail' => $item->description,
            'type' => $item->is_service ? __('panel.items.is_service') : __('panel.items.item'),
            'type_color' => 'gray',
            // Formatted here rather than in the browser: a panel is server
            // rendered, and the currency is resolved once for the page.
            'meta' => $item->isComplimentary() ? $complimentary : $item->formattedPrice($currency),
            'state' => $item->availability->label(),
            'state_color' => $item->availability->color(),
        ];
    }

    /**
     * One combo, with its price and how many items are in it.
     *
     * @return array<string, mixed>
     */
    private static function comboRow(MenuCombo $combo, Currency $currency): array
    {
        $contents = (int) $combo->getAttribute('combo_items_count');

        return [
            '__key' => ApplyMenuArrangement::comboKey($combo->getKey()),
            'kind' => self::COMBO,
            'block' => null,
            'id' => $combo->getKey(),
            'depth' => 1,
            'name' => $combo->name,
            'detail' => $combo->description,
            'type' => __('panel.combos.section'),
            'type_color' => 'gray',
            'meta' => $combo->formattedPrice($currency).' · '.trans_choice('panel.arrangement.items_count', $contents, ['count' => $contents]),
            'state' => $combo->availability->label(),
            'state_color' => $combo->availability->color(),
        ];
    }

    /**
     * The name cell: indented to its depth, with what it is under it.
     *
     * Inline styles rather than utility classes — a panel is served Filament's
     * own stylesheet and carries no general Tailwind (.ai/rules/filament.md).
     *
     * @param  array<string, mixed>  $record
     */
    private static function nameHtml(array $record): string
    {
        $indent = ((int) $record['depth']) * 1.25;
        $isHeading = in_array($record['kind'], [self::BLOCK, self::CATEGORY, self::SUB_CATEGORY], true);
        $detail = filled($record['detail'])
            ? '<div style="font-size:0.75rem;opacity:0.65;margin-top:0.125rem">'.e((string) $record['detail']).'</div>'
            : '';

        return '<div style="padding-inline-start:'.$indent.'rem">'
            .'<div style="font-weight:'.($isHeading ? '600' : '400').'">'.e((string) $record['name']).'</div>'
            .$detail
            .'</div>';
    }

    /**
     * A new top-level category, at the bottom of the menu where whoever added it looks for it.
     */
    private static function createCategoryAction(string $name, Menu $menu): Action
    {
        return Action::make($name)
            ->label(__('panel.arrangement.create_category'))
            ->icon(Heroicon::OutlinedPlus)
            ->schema(fn (Schema $schema): Schema => MenuCategoryForm::configure($schema, $menu->getKey()))
            ->action(function (array $data) use ($menu): void {
                Gate::authorize('create', MenuCategory::class);

                $menu->categories()->create([
                    ...$data,
                    'position' => self::nextTopLevelPosition($menu),
                ]);

                Notification::make()->title(__('panel.arrangement.category_created'))->success()->send();
            });
    }

    private static function renameAction(Menu $menu): Action
    {
        return Action::make('rename')
            ->label(__('panel.arrangement.edit'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->schema(fn (array $record, Schema $schema): Schema => self::categoryForm($schema, $menu, $record))
            ->fillForm(function (array $record) use ($menu): array {
                $category = self::category($record, $menu);
                $data = $category->attributesToArray();

                return $record['kind'] === self::CATEGORY
                    ? MenuCategoryForm::fillTranslations($data, $category)
                    : MenuSubCategoryForm::fillTranslations($data, $category);
            })
            ->action(function (array $data, array $record) use ($menu): void {
                $category = self::category($record, $menu);

                Gate::authorize('update', $category);

                $category->update($data);

                Notification::make()->title(__('panel.arrangement.saved'))->success()->send();
            });
    }

    private static function createSubCategoryAction(Menu $menu): Action
    {
        return Action::make('createSubCategory')
            ->label(__('panel.sub_categories.create'))
            ->icon(Heroicon::OutlinedSquares2x2)
            // The category it was opened from is the parent's default rather
            // than filled in: filling a form with anything at all skips every
            // other field's default, "showing on the menu" included.
            ->schema(fn (array $record, Schema $schema): Schema => MenuSubCategoryForm::configure($schema, $menu->getKey(), parentId: (int) $record['id']))
            ->action(function (array $data) use ($menu): void {
                Gate::authorize('create', MenuCategory::class);

                $parent = MenuCategory::query()
                    ->where('menu_id', $menu->getKey())
                    ->findOrFail((int) $data['parent_id']);

                $menu->menuCategories()->create([
                    ...$data,
                    'position' => self::nextPosition($parent->children()),
                ]);

                Notification::make()->title(__('panel.arrangement.sub_category_created'))->success()->send();
            });
    }

    /**
     * Carrying a whole section onto another of this tenant's menus.
     *
     * An action rather than a select on the form for the reason in
     * .ai/rules/actions-menus.md: the page a category is renamed on *is* the
     * menu, so there is no "which menu" field to change, and this does one
     * thing no edit does — it unfeatures every item in the branch.
     */
    private static function moveCategoryAction(Menu $menu): Action
    {
        return Action::make('moveToMenu')
            ->label(__('panel.categories.move'))
            ->icon(Heroicon::OutlinedArrowRightCircle)
            ->modalHeading(__('panel.categories.move'))
            ->modalDescription(__('panel.categories.move_help'))
            ->schema(fn (array $record): array => [
                Select::make('menu_id')
                    ->label(__('panel.categories.move_target'))
                    ->options(fn (): array => self::otherMenus($menu))
                    ->required()
                    ->native(false)
                    ->prefixIcon(Heroicon::OutlinedBookOpen)
                    // Uniqueness is per menu and built on the English name, so
                    // a clash is caught here rather than after the form has passed.
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record, $menu): void {
                        if (MoveCategoryToMenu::nameIsTakenOn(self::category($record, $menu), (int) $value)) {
                            $fail(__('panel.categories.unique'));
                        }
                    }),
            ])
            ->action(function (array $data, array $record) use ($menu): void {
                $category = self::category($record, $menu);

                Gate::authorize('update', $category);

                app(MoveCategoryToMenu::class)($category, Menu::query()->whereKey($data['menu_id'])->firstOrFail());

                Notification::make()->title(__('panel.categories.moved'))->success()->send();
            });
    }

    private static function deleteCategoryAction(Menu $menu): Action
    {
        return Action::make('delete')
            ->label(__('panel.arrangement.delete'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('panel.arrangement.delete'))
            ->modalDescription(fn (array $record): string => (string) ($record['kind'] === self::CATEGORY
                ? __('panel.categories.delete_warning')
                : __('panel.sub_categories.delete_warning')))
            ->action(function (array $record) use ($menu): void {
                $category = self::category($record, $menu);

                Gate::authorize('delete', $category);

                $category->delete();

                Notification::make()->title(__('panel.arrangement.deleted'))->success()->send();
            });
    }

    /**
     * A new item, filed under the category or sub-category the button was pressed on.
     *
     * The category is the select's default rather than filled in, for the same
     * reason as a new sub-category's parent: filling the form would skip the
     * item's other defaults, its availability and diet among them.
     */
    private static function createItemAction(): Action
    {
        return Action::make('createItem')
            ->label(__('panel.arrangement.add_item'))
            ->icon(Heroicon::OutlinedPlus)
            ->slideOver()
            ->modalHeading(__('panel.items.create'))
            ->schema(fn (array $record, Schema $schema): Schema => MenuItemForm::configure(
                $schema->model(MenuItem::class),
                categoryId: (int) $record['id'],
            ))
            ->action(function (array $data, Schema $schema): void {
                Gate::authorize('create', MenuItem::class);

                $item = MenuItem::query()->create([
                    ...MenuItemForm::storePricing($data),
                    // At the bottom of its category, where whoever added it looks for it.
                    'position' => ((int) MenuItem::query()->where('menu_category_id', $data['menu_category_id'])->max('position')) + 1,
                ]);

                $schema->model($item)->saveRelationships();

                Notification::make()->title(__('panel.arrangement.item_created'))->success()->send();
            });
    }

    private static function editItemAction(Menu $menu): Action
    {
        return Action::make('editItem')
            ->label(__('panel.arrangement.edit'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->iconButton()
            ->slideOver()
            ->modalHeading(fn (array $record): string => (string) $record['name'])
            ->schema(fn (array $record, Schema $schema): Schema => MenuItemForm::configure($schema->model(self::item($record, $menu))))
            ->fillForm(function (array $record) use ($menu): array {
                $item = self::item($record, $menu);

                return MenuItemForm::fillTranslations(MenuItemForm::fillPricing($item->attributesToArray()), $item);
            })
            ->action(function (array $data, array $record, Schema $schema) use ($menu): void {
                $item = self::item($record, $menu);

                Gate::authorize('update', $item);

                $item->update(MenuItemForm::storePricing($data));
                $schema->model($item)->saveRelationships();

                Notification::make()->title(__('panel.arrangement.saved'))->success()->send();
            });
    }

    private static function deleteItemAction(Menu $menu): Action
    {
        return Action::make('deleteItem')
            ->label(__('panel.arrangement.delete'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('panel.arrangement.delete'))
            ->modalDescription(__('panel.items.delete_warning'))
            ->action(function (array $record) use ($menu): void {
                $item = self::item($record, $menu);

                Gate::authorize('delete', $item);

                $item->delete();

                Notification::make()->title(__('panel.arrangement.deleted'))->success()->send();
            });
    }

    /**
     * Put some of this menu's items at the end of what it leads with.
     *
     * The Featured toggle on the item form does the same to one item; this is
     * the way to do it from the menu, several at once. Both write `is_featured`,
     * and MenuItemObserver keeps an item that leaves the menu from staying on it.
     */
    private static function featureItemsAction(string $name, Menu $menu): Action
    {
        return Action::make($name)
            ->label(__('panel.arrangement.feature_items'))
            ->icon(Heroicon::OutlinedStar)
            ->modalHeading(__('panel.arrangement.feature_items'))
            ->modalSubmitActionLabel(__('panel.arrangement.feature'))
            ->schema([
                Select::make('items')
                    ->label(__('panel.arrangement.items_to_feature'))
                    ->options(fn (): array => self::unfeaturedItemOptions($menu))
                    ->multiple()
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data) use ($menu): void {
                $chosen = array_map(intval(...), $data['items']);

                $items = MenuItem::query()
                    ->onMenu($menu->getKey())
                    ->where('is_featured', false)
                    ->whereKey($chosen)
                    ->get()
                    ->sortBy(fn (MenuItem $item): int => (int) array_search($item->getKey(), $chosen, true));

                $position = self::nextFeaturedPosition($menu);

                foreach ($items as $item) {
                    Gate::authorize('update', $item);

                    $item->update(['is_featured' => true, 'featured_position' => $position++]);
                }

                Notification::make()->title(__('panel.arrangement.featured'))->success()->send();
            });
    }

    private static function unfeatureAction(Menu $menu): Action
    {
        return Action::make('unfeature')
            ->label(__('panel.arrangement.unfeature'))
            ->icon(Heroicon::OutlinedXMark)
            ->action(function (array $record) use ($menu): void {
                $item = self::item($record, $menu);

                Gate::authorize('update', $item);

                $item->update(['is_featured' => false, 'featured_position' => 0]);

                Notification::make()->title(__('panel.arrangement.unfeatured'))->success()->send();
            });
    }

    private static function createComboAction(string $name, Menu $menu): Action
    {
        return Action::make($name)
            ->label(__('panel.combos.create'))
            ->icon(Heroicon::OutlinedSparkles)
            ->slideOver()
            ->modalHeading(__('panel.combos.create'))
            ->schema(fn (Schema $schema): Schema => MenuComboForm::configure($schema->model(MenuCombo::class), $menu->getKey()))
            ->action(function (array $data, Schema $schema) use ($menu): void {
                Gate::authorize('create', MenuCombo::class);

                $combo = $menu->combos()->create([
                    ...PricingFields::store($data),
                    'position' => self::nextPosition($menu->combos()),
                ]);

                $schema->model($combo)->saveRelationships();

                Notification::make()->title(__('panel.arrangement.combo_created'))->success()->send();
            });
    }

    private static function editComboAction(Menu $menu): Action
    {
        return Action::make('editCombo')
            ->label(__('panel.arrangement.edit'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->iconButton()
            ->slideOver()
            ->modalHeading(fn (array $record): string => (string) $record['name'])
            ->schema(fn (array $record, Schema $schema): Schema => MenuComboForm::configure(
                $schema->model(self::combo($record, $menu)),
                $menu->getKey(),
            ))
            ->fillForm(function (array $record) use ($menu): array {
                $combo = self::combo($record, $menu);

                return MenuComboForm::fillTranslations(PricingFields::fill($combo->attributesToArray()), $combo);
            })
            ->action(function (array $data, array $record, Schema $schema) use ($menu): void {
                $combo = self::combo($record, $menu);

                Gate::authorize('update', $combo);

                $combo->update(PricingFields::store($data));
                $schema->model($combo)->saveRelationships();

                Notification::make()->title(__('panel.arrangement.saved'))->success()->send();
            });
    }

    private static function deleteComboAction(Menu $menu): Action
    {
        return Action::make('deleteCombo')
            ->label(__('panel.arrangement.delete'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('panel.arrangement.delete'))
            ->modalDescription(__('panel.combos.delete_warning'))
            ->action(function (array $record) use ($menu): void {
                $combo = self::combo($record, $menu);

                Gate::authorize('delete', $combo);

                $combo->delete();

                Notification::make()->title(__('panel.arrangement.deleted'))->success()->send();
            });
    }

    /**
     * The form for whichever level of category a row is.
     *
     * The record is handed to the form rather than found by it: these rows are
     * arrays, so the uniqueness rule has no record to ignore on its own and
     * would refuse a category saved under the name it already has.
     *
     * @param  array<string, mixed>  $record
     */
    private static function categoryForm(Schema $schema, Menu $menu, array $record): Schema
    {
        $category = self::category($record, $menu);

        return $record['kind'] === self::CATEGORY
            ? MenuCategoryForm::configure($schema, $menu->getKey(), $category)
            : MenuSubCategoryForm::configure($schema, $menu->getKey(), $category);
    }

    /**
     * The category a row stands for, scoped to the menu being arranged.
     *
     * @param  array<string, mixed>  $record
     */
    private static function category(array $record, Menu $menu): MenuCategory
    {
        $menuId = $menu->getKey();
        $categoryId = (int) $record['id'];

        // The schema, the form fill, a validation rule and the write all ask
        // for the row an action is about, so it is read once for the request.
        return once(fn (): MenuCategory => MenuCategory::query()
            ->where('menu_id', $menuId)
            ->findOrFail($categoryId));
    }

    /**
     * The item a row stands for, and only if it is on the menu being arranged.
     *
     * @param  array<string, mixed>  $record
     */
    private static function item(array $record, Menu $menu): MenuItem
    {
        $menuId = $menu->getKey();
        $itemId = (int) $record['id'];

        return once(fn (): MenuItem => MenuItem::query()
            ->onMenu($menuId)
            ->findOrFail($itemId));
    }

    /**
     * The combo a row stands for, scoped to the menu being arranged.
     *
     * @param  array<string, mixed>  $record
     */
    private static function combo(array $record, Menu $menu): MenuCombo
    {
        $menuId = $menu->getKey();
        $comboId = (int) $record['id'];

        return once(fn (): MenuCombo => MenuCombo::query()
            ->where('menu_id', $menuId)
            ->findOrFail($comboId));
    }

    /**
     * This menu's items that are not featured yet, labelled with where each is filed.
     *
     * @return array<int, string>
     */
    private static function unfeaturedItemOptions(Menu $menu): array
    {
        $menuId = $menu->getKey();

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

    /**
     * @param  array<string, mixed>  $record
     */
    private static function isCategoryRow(array $record): bool
    {
        return in_array($record['kind'], [self::CATEGORY, self::SUB_CATEGORY], strict: true);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private static function isItemRow(array $record): bool
    {
        return in_array($record['kind'], [self::ITEM, self::FEATURED_ITEM], strict: true);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private static function isBlockRow(array $record, MenuBlockType $type): bool
    {
        return $record['kind'] === self::BLOCK && $record['block'] === $type->value;
    }

    /**
     * The action a click on a row opens: whatever edits it.
     *
     * @param  array<string, mixed>  $record
     */
    private static function editActionFor(array $record): ?string
    {
        return match ($record['kind']) {
            self::CATEGORY, self::SUB_CATEGORY => 'rename',
            self::ITEM, self::FEATURED_ITEM => 'editItem',
            self::COMBO => 'editCombo',
            default => null,
        };
    }

    /**
     * The place at the end of a list, so something new lands where it is looked
     * for rather than at the top.
     *
     * @param  HasMany<MenuCategory, MenuCategory>|HasMany<MenuCombo, Menu>  $siblings
     */
    private static function nextPosition(HasMany $siblings): int
    {
        return ((int) $siblings->max('position')) + 1;
    }

    /**
     * The place at the end of the menu's top level, which its categories and blocks share.
     */
    private static function nextTopLevelPosition(Menu $menu): int
    {
        return max((int) $menu->categories()->max('position'), (int) $menu->blocks()->max('position')) + 1;
    }

    /**
     * The place at the end of the featured list, or the first place when it is empty.
     */
    private static function nextFeaturedPosition(Menu $menu): int
    {
        $last = MenuItem::query()->featuredOnMenu($menu->getKey())->max('featured_position');

        return $last === null ? 0 : ((int) $last) + 1;
    }

    /**
     * The menus a category could be moved onto: this tenant's, minus this one.
     *
     * @return array<int, string>
     */
    private static function otherMenus(Menu $menu): array
    {
        return array_diff_key(MenuCategoryForm::menuOptions(), [$menu->getKey() => null]);
    }
}
