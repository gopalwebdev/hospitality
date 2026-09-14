<?php

namespace App\Filament\Tenant\Resources\Menus\Tables;

use App\Actions\Menus\ApplyMenuArrangement;
use App\Actions\Menus\MoveCategoryToMenu;
use App\Enums\MenuBlockType;
use App\Filament\Tables\Reordering;
use App\Filament\Tenant\Resources\Menus\Pages\ArrangeMenu;
use App\Filament\Tenant\Resources\Menus\RelationManagers\CategoryItemsRelationManager;
use App\Filament\Tenant\Resources\Menus\RelationManagers\CombosRelationManager;
use App\Filament\Tenant\Resources\Menus\RelationManagers\FeaturedItemsRelationManager;
use App\Filament\Tenant\Resources\Menus\Schemas\MenuCategoryForm;
use App\Filament\Tenant\Resources\Menus\Schemas\MenuSubCategoryForm;
use App\Models\Menu;
use App\Models\MenuBlock;
use App\Models\MenuCategory;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * A menu's outline, in the order a guest reads it: its featured items, its combos, its categories and their sub-categories.
 *
 * Nothing inside those is listed. A click on a row opens it in a modal holding
 * a table of its own — a category's items, the featured items, the combos —
 * where they are put in order, added, edited and deleted. The whole menu used
 * to be one list here, items and all, and the project owner asked for the
 * outline alone: with every item on it a drag could be dropped anywhere, and a
 * featured item dropped among a category's items looked filed there.
 *
 * It is built on Filament's custom data (`records()`) rather than on a query,
 * because the rows are categories plus blocks that may not be rows anywhere
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
 */
class MenuArrangementTable
{
    private const string BLOCK = 'block';

    private const string CATEGORY = 'category';

    private const string SUB_CATEGORY = 'sub_category';

    /**
     * The list a top-level row is dragged within, mirroring ApplyMenuArrangement.
     *
     * A sub-category's list is its parent's, `sub-<parent id>`.
     */
    private const string TOP_LEVEL_LIST = 'top';

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
            // An outline is one screen's worth, and a drag has to be able to
            // carry the last category to the top.
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->label(__('panel.arrangement.name'))
                    // Built as HTML so a sub-category is drawn indented under
                    // its category, with a marker saying so.
                    ->html()
                    ->formatStateUsing(fn (array $record): string => self::nameHtml($record)),

                TextColumn::make('type')
                    ->label(__('panel.arrangement.type'))
                    ->badge()
                    ->icon(fn (array $record): Heroicon => $record['type_icon'])
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
            // What each row is, and the list it is dragged within, as classes for
            // the page's own styles and its drag guard to read — see
            // resources/views/filament/tenant/resources/menus/pages/arrange-menu.blade.php.
            ->recordClasses(fn (array $record): array => [
                'menu-row',
                'menu-row--'.($record['block'] ?? $record['kind']),
                'menu-list--'.$record['list'],
            ])
            ->headerActions([
                self::createCategoryAction('createCategory', $menu)->visible($mayManage),
                // Always here, because a block with nothing in it has no row to click.
                self::blockAction('openFeatured', MenuBlockType::Featured, $menu),
                self::blockAction('openCombos', MenuBlockType::Combos, $menu),
            ])
            // Separate icon buttons, each named on hover and coloured by what it
            // does. A row shows only the ones that apply to it.
            ->recordActions([
                self::iconButton(self::openAction($menu)),
                self::iconButton(self::createSubCategoryAction($menu))
                    // Two levels and no more, so only a top-level category holds one.
                    ->visible(fn (array $record): bool => $mayManage && $record['kind'] === self::CATEGORY),
                self::iconButton(self::renameAction($menu))
                    ->visible(fn (array $record): bool => $mayManage && self::isCategoryRow($record)),
                self::iconButton(self::moveCategoryAction($menu))
                    ->visible(fn (array $record): bool => $mayManage && $record['kind'] === self::CATEGORY),
                self::iconButton(self::deleteCategoryAction($menu))
                    ->visible(fn (array $record): bool => $mayManage && self::isCategoryRow($record)),
            ])
            // Clicking a row opens what is in it.
            ->recordAction('open')
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
     * Every row of this menu's outline, in reading order and keyed for the table.
     *
     * Three queries whatever the menu holds: its categories at both levels with
     * a count of the items in each, its placed blocks, and a count of what is in
     * each block.
     *
     * @return Collection<string, array<string, mixed>>
     */
    private static function rows(Menu $menu): Collection
    {
        $categories = MenuCategory::query()
            ->select(['id', 'parent_id', 'name', 'position', 'is_active'])
            ->where('menu_id', $menu->getKey())
            ->withCount('menuItems')
            ->inMenuOrder()
            ->get();

        $blocks = MenuBlock::query()
            ->select(['id', 'menu_id', 'type', 'position'])
            ->where('menu_id', $menu->getKey())
            ->get();

        $contents = Menu::query()
            ->select('id')
            ->whereKey($menu->getKey())
            ->withCount([
                'combos',
                'menuItems as featured_items_count' => fn (Builder $items): Builder => $items->where('is_featured', true),
            ])
            ->sole();

        $subCategories = $categories
            ->filter(fn (MenuCategory $category): bool => $category->isSubCategory())
            ->groupBy('parent_id');

        $topLevel = $categories->filter(fn (MenuCategory $category): bool => $category->isTopLevel());

        $rows = [];

        foreach ($menu->readingOrder($topLevel, $blocks) as $entry) {
            if ($entry instanceof MenuBlock) {
                $count = (int) $contents->getAttribute(match ($entry->type) {
                    MenuBlockType::Featured => 'featured_items_count',
                    MenuBlockType::Combos => 'combos_count',
                });

                // An empty "Featured items · No items" row used to head every
                // menu. A block with nothing in it is not drawn; the header's
                // buttons open it instead.
                if ($count > 0) {
                    $rows[] = self::blockRow($entry, $count);
                }

                continue;
            }

            $children = $subCategories->get($entry->getKey(), new Collection);

            $rows[] = self::categoryRow($entry, self::CATEGORY, subCategoryCount: $children->count());

            foreach ($children as $child) {
                $rows[] = self::categoryRow($child, self::SUB_CATEGORY, subCategoryCount: 0);
            }
        }

        return collect($rows)->keyBy('__key');
    }

    /**
     * A block: what it is and how much is in it.
     *
     * @return array<string, mixed>
     */
    private static function blockRow(MenuBlock $block, int $count): array
    {
        return [
            '__key' => ApplyMenuArrangement::blockKey($block),
            'kind' => self::BLOCK,
            'list' => self::TOP_LEVEL_LIST,
            'block' => $block->type->value,
            'id' => $block->getKey(),
            'name' => $block->type->label(),
            'type' => __('panel.arrangement.block'),
            'type_icon' => self::blockIcon($block->type),
            'type_color' => self::blockColor($block->type),
            'meta' => match ($block->type) {
                MenuBlockType::Featured => trans_choice('panel.arrangement.items_count', $count, ['count' => $count]),
                MenuBlockType::Combos => trans_choice('panel.arrangement.combos_count', $count, ['count' => $count]),
            },
            'state' => null,
            'state_color' => null,
        ];
    }

    /**
     * A category at either level.
     *
     * @return array<string, mixed>
     */
    private static function categoryRow(MenuCategory $category, string $kind, int $subCategoryCount): array
    {
        $isTopLevel = $kind === self::CATEGORY;
        $itemCount = (int) $category->getAttribute('menu_items_count');
        $items = trans_choice('panel.arrangement.items_count', $itemCount, ['count' => $itemCount]);

        return [
            '__key' => ApplyMenuArrangement::categoryKey($category->getKey()),
            'kind' => $kind,
            'list' => $isTopLevel ? self::TOP_LEVEL_LIST : 'sub-'.$category->parent_id,
            'block' => null,
            'id' => $category->getKey(),
            'name' => $category->name,
            'type' => $isTopLevel
                ? __('panel.categories.section')
                : __('panel.sub_categories.section'),
            'type_icon' => $isTopLevel ? Heroicon::OutlinedRectangleStack : Heroicon::OutlinedSquare2Stack,
            // Violet rather than primary: the tenant panel's primary is amber,
            // which is the featured items' colour.
            'type_color' => $isTopLevel ? 'violet' : 'info',
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
     * The name cell, with a sub-category indented under its category.
     *
     * Inline styles rather than utility classes — a panel is served Filament's
     * own stylesheet and carries no general Tailwind (.ai/rules/filament.md).
     *
     * @param  array<string, mixed>  $record
     */
    private static function nameHtml(array $record): string
    {
        $name = e((string) $record['name']);

        if ($record['kind'] !== self::SUB_CATEGORY) {
            return '<span style="font-weight:600">'.$name.'</span>';
        }

        return '<span style="display:inline-flex;align-items:center;gap:0.5rem;padding-inline-start:1.25rem;font-weight:500">'
            .'<span aria-hidden="true" style="opacity:0.45">↳</span>'
            .$name
            .'</span>';
    }

    private static function blockIcon(MenuBlockType $type): Heroicon
    {
        return match ($type) {
            MenuBlockType::Featured => Heroicon::OutlinedStar,
            MenuBlockType::Combos => Heroicon::OutlinedSparkles,
        };
    }

    private static function blockColor(MenuBlockType $type): string
    {
        return match ($type) {
            MenuBlockType::Featured => 'warning',
            MenuBlockType::Combos => 'success',
        };
    }

    /**
     * What a row holds, in a modal over the menu: a category's items, or what is in a block.
     */
    private static function openAction(Menu $menu): Action
    {
        return self::contentsModal(Action::make('open'))
            ->label(__('panel.arrangement.open'))
            ->icon(Heroicon::OutlinedQueueList)
            ->color('primary')
            ->modalHeading(fn (array $record): string => (string) $record['name'])
            ->modalIcon(fn (array $record): Heroicon => $record['type_icon'])
            ->modalIconColor(fn (array $record): string => $record['type_color'])
            ->schema(fn (array $record): array => [self::contentsOf($record, $menu)]);
    }

    /**
     * A block's contents opened from the header, the only way into a block with nothing in it yet.
     */
    private static function blockAction(string $name, MenuBlockType $type, Menu $menu): Action
    {
        return self::contentsModal(Action::make($name))
            ->label($type->label())
            ->icon(self::blockIcon($type))
            ->color(self::blockColor($type))
            ->outlined()
            ->modalHeading($type->label())
            ->modalIcon(self::blockIcon($type))
            ->modalIconColor(self::blockColor($type))
            ->schema([self::blockContents($type, $menu)]);
    }

    /**
     * The modal a row's contents open in.
     *
     * Wide, because it holds a table, and with nothing to submit: every change
     * in that table is saved as it is made. Closing it calls unmountAction(),
     * which redraws this page, so the counts beside each row are current again.
     *
     * Not wrapped in a form, which Filament does to every action modal unless
     * told otherwise. The table inside opens modals of its own, and those are
     * forms: the browser's parser drops a form inside a form, and their save
     * buttons would have submitted this modal instead.
     */
    private static function contentsModal(Action $action): Action
    {
        return $action
            ->formWrapper(false)
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('panel.arrangement.close'));
    }

    /**
     * The table a row opens.
     *
     * @param  array<string, mixed>  $record
     */
    private static function contentsOf(array $record, Menu $menu): Livewire
    {
        if ($record['kind'] === self::BLOCK) {
            return self::blockContents(MenuBlockType::from((string) $record['block']), $menu);
        }

        return Livewire::make(CategoryItemsRelationManager::class, fn (): array => [
            'ownerRecord' => self::category($record, $menu),
            'pageClass' => ArrangeMenu::class,
        ])->key('contents-category-'.$record['id']);
    }

    private static function blockContents(MenuBlockType $type, Menu $menu): Livewire
    {
        $manager = match ($type) {
            MenuBlockType::Featured => FeaturedItemsRelationManager::class,
            MenuBlockType::Combos => CombosRelationManager::class,
        };

        return Livewire::make($manager, ['ownerRecord' => $menu, 'pageClass' => ArrangeMenu::class])
            ->key('contents-'.$type->value);
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
            ->color('gray')
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
            ->icon(Heroicon::OutlinedSquaresPlus)
            ->color('info')
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
            ->color('gray')
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
     * One of a row's actions as an icon button, named by a tooltip rather than in text.
     *
     * The label is kept rather than removed: it still heads the action's modal
     * and is what a screen reader announces.
     */
    private static function iconButton(Action $action): Action
    {
        $label = $action->getLabel();

        return $action
            ->iconButton()
            ->tooltip(is_string($label) ? $label : null);
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
     * @param  array<string, mixed>  $record
     */
    private static function isCategoryRow(array $record): bool
    {
        return in_array($record['kind'], [self::CATEGORY, self::SUB_CATEGORY], strict: true);
    }

    /**
     * The place at the end of a category's sub-categories, so a new one lands where it is looked for.
     *
     * @param  HasMany<MenuCategory, MenuCategory>  $siblings
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
     * The menus a category could be moved onto: this tenant's, minus this one.
     *
     * @return array<int, string>
     */
    private static function otherMenus(Menu $menu): array
    {
        return array_diff_key(MenuCategoryForm::menuOptions(), [$menu->getKey() => null]);
    }
}
