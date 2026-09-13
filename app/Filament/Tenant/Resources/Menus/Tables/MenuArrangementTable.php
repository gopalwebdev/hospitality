<?php

namespace App\Filament\Restaurant\Resources\Menus\Tables;

use App\Actions\Menus\ApplyMenuArrangement;
use App\Actions\Menus\MoveCategoryToMenu;
use App\Enums\Currency;
use App\Enums\MenuBlock;
use App\Filament\Restaurant\Resources\MenuItems\MenuItemResource;
use App\Filament\Restaurant\Resources\Menus\MenuResource;
use App\Filament\Restaurant\Resources\Menus\Schemas\MenuCategoryForm;
use App\Filament\Restaurant\Resources\Menus\Schemas\MenuSubCategoryForm;
use App\Filament\Schemas\PricingFields;
use App\Filament\Tables\Reordering;
use App\Models\Menu;
use App\Models\MenuCategory;
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
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * A whole menu as one list: the two rails, every category, every subdivision
 * and every dish, in the order a guest reads them.
 *
 * This replaced a page of four tables — categories, sub-categories, featured
 * dishes, combos — where the thing an admin most wanted to see, the dishes
 * under each heading, was on another page entirely, and where the rails could
 * not be moved at all. One table now says what the menu *is*, and one drag says
 * what order it is read in.
 *
 * It is built on Filament's custom data (`records()`) rather than on a query,
 * because the rows are three different models plus two rails that are not rows
 * anywhere. Two consequences before editing:
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
 * Dishes are listed and dragged here but not edited here. The dish form carries
 * prices, tax and a repeater of additions bound to a relationship, and a
 * repeater needs a real record behind the schema — so every dish row links to
 * the dishes page, which is where a dish has always been edited.
 */
class MenuArrangementTable
{
    private const string RAIL = 'rail';

    private const string CATEGORY = 'category';

    private const string SUB_CATEGORY = 'sub_category';

    private const string DISH = 'dish';

    public static function configure(Table $table, Menu $menu): Table
    {
        // Asked once for the page rather than per row per action: every
        // mutation here is menu.manage, and MenuCategoryPolicy answers it
        // without looking at the record. The record *is* checked, with its real
        // policy method, at the moment an action writes — see the closures
        // below, where the model has been loaded anyway.
        $mayManage = Gate::allows('create', MenuCategory::class);

        return $table
            ->records(fn (): Collection => self::rows($menu))
            // A menu is one screen's worth of structure, and a drag has to be
            // able to carry a dish at the bottom to the top of the list.
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->label(__('panel.arrangement.name'))
                    // The indentation is the tree: a dish sits under the
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
                    // A rail is neither showing nor hidden: it is drawn when it
                    // has something in it.
                    ->placeholder(''),
            ])
            ->headerActions([
                Action::make('createCategory')
                    ->label(__('panel.arrangement.create_category'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->visible($mayManage)
                    ->schema(fn (Schema $schema): Schema => MenuCategoryForm::configure($schema, $menu->getKey()))
                    ->action(function (array $data) use ($menu): void {
                        Gate::authorize('create', MenuCategory::class);

                        $menu->categories()->create([
                            ...$data,
                            // New sections land at the bottom of the menu,
                            // where whoever added one looks for it.
                            'position' => self::nextPosition($menu->categories()),
                        ]);

                        Notification::make()->title(__('panel.arrangement.category_created'))->success()->send();
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    self::renameAction($menu, $mayManage),
                    self::createSubCategoryAction($menu, $mayManage),
                    self::moveCategoryAction($menu, $mayManage),
                    self::openDishesAction(),
                    self::deleteAction($menu, $mayManage),
                ])
                    ->label(__('panel.arrangement.actions'))
                    ->icon(Heroicon::OutlinedEllipsisHorizontal)
                    ->color('gray')
                    // A rail is arranged here and filled in elsewhere, so its
                    // row carries the link rather than a menu of actions.
                    ->visible(fn (array $record): bool => $record['kind'] !== self::RAIL),

                Action::make('openRail')
                    ->label(__('panel.arrangement.open_rail'))
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('gray')
                    ->link()
                    ->url(fn (array $record): string => (string) $record['url'])
                    ->visible(fn (array $record): bool => $record['kind'] === self::RAIL),
            ])
            // Filament renders the handles on this call and short-circuits the
            // write on it too, so the condition is the authorization: putting a
            // menu in order is changing it. See ArrangeMenu::reorderTable().
            ->reorderable('position', condition: fn (): bool => Gate::allows('reorder', MenuCategory::class))
            ->reorderRecordsTriggerAction(Reordering::trigger())
            ->emptyStateHeading(__('panel.arrangement.empty_heading'))
            ->emptyStateDescription(__('panel.arrangement.empty_description'))
            ->emptyStateIcon(Heroicon::OutlinedRectangleStack);
    }

    /**
     * Every row of this menu, in reading order and keyed for the table.
     *
     * Three queries whatever the menu holds: its categories at both levels, the
     * dishes in them, and a count of its combos. The featured count comes from
     * the dishes already loaded rather than being asked for again.
     *
     * @return Collection<string, array<string, mixed>>
     */
    private static function rows(Menu $menu): Collection
    {
        $currency = PricingFields::currency();

        $categories = MenuCategory::query()
            ->select(['id', 'parent_id', 'name', 'position', 'is_active'])
            ->where('menu_id', $menu->getKey())
            ->with(['menuItems' => fn ($items) => $items
                ->select(['id', 'menu_category_id', 'name', 'description', 'price_minor_units', 'availability', 'is_featured', 'position'])
                ->inMenuOrder()])
            ->inMenuOrder()
            ->get();

        $subCategories = $categories
            ->filter(fn (MenuCategory $category): bool => $category->isSubCategory())
            ->groupBy('parent_id');

        $railCounts = [
            MenuBlock::Featured->value => $categories->sum(
                fn (MenuCategory $category): int => $category->menuItems->where('is_featured', true)->count(),
            ),
            MenuBlock::Combos->value => $menu->combos()->count(),
        ];

        $topLevel = $categories->filter(fn (MenuCategory $category): bool => $category->isTopLevel());

        $rows = [];

        foreach ($menu->readingOrder($topLevel) as $block) {
            if ($block instanceof MenuBlock) {
                $rows[] = self::railRow($block, $menu, $railCounts[$block->value]);

                continue;
            }

            $children = $subCategories->get($block->getKey(), new Collection);

            $rows[] = self::categoryRow($block, self::CATEGORY, depth: 0, subCategoryCount: $children->count());

            foreach ($block->menuItems as $dish) {
                $rows[] = self::dishRow($dish, $currency, depth: 1);
            }

            foreach ($children as $child) {
                $rows[] = self::categoryRow($child, self::SUB_CATEGORY, depth: 1, subCategoryCount: 0);

                foreach ($child->menuItems as $dish) {
                    $rows[] = self::dishRow($dish, $currency, depth: 2);
                }
            }
        }

        return collect($rows)->keyBy('__key');
    }

    /**
     * One of the two rails: what it is, how much is in it, where to fill it.
     *
     * @return array<string, mixed>
     */
    private static function railRow(MenuBlock $rail, Menu $menu, int $count): array
    {
        return [
            '__key' => $rail->value,
            'kind' => self::RAIL,
            'id' => null,
            'category_id' => null,
            'depth' => 0,
            'name' => $rail->label(),
            'detail' => $rail->description(),
            'type' => __('panel.arrangement.rail'),
            'type_color' => 'warning',
            'meta' => $rail === MenuBlock::Featured
                ? trans_choice('panel.arrangement.dishes_count', $count, ['count' => $count])
                : trans_choice('panel.arrangement.combos_count', $count, ['count' => $count]),
            'state' => null,
            'state_color' => null,
            'url' => MenuResource::getUrl(
                $rail === MenuBlock::Featured ? 'featured' : 'combos',
                ['record' => $menu],
            ),
        ];
    }

    /**
     * A category at either level.
     *
     * @return array<string, mixed>
     */
    private static function categoryRow(MenuCategory $category, string $kind, int $depth, int $subCategoryCount): array
    {
        $dishCount = $category->menuItems->count();
        $dishes = trans_choice('panel.arrangement.dishes_count', $dishCount, ['count' => $dishCount]);

        return [
            '__key' => ApplyMenuArrangement::categoryKey($category->getKey()),
            'kind' => $kind,
            'id' => $category->getKey(),
            'category_id' => $category->getKey(),
            'depth' => $depth,
            'name' => $category->name,
            'detail' => null,
            'type' => $kind === self::CATEGORY
                ? __('panel.categories.section')
                : __('panel.sub_categories.section'),
            'type_color' => $kind === self::CATEGORY ? 'primary' : 'info',
            'meta' => $subCategoryCount > 0
                ? $dishes.' · '.trans_choice('panel.arrangement.sub_categories_count', $subCategoryCount, ['count' => $subCategoryCount])
                : $dishes,
            'state' => $category->is_active
                ? __('panel.shared.showing')
                : __('panel.arrangement.hidden'),
            'state_color' => $category->is_active ? 'success' : 'gray',
            'url' => null,
        ];
    }

    /**
     * One dish, under whichever heading it is filed.
     *
     * @return array<string, mixed>
     */
    private static function dishRow(MenuItem $dish, Currency $currency, int $depth): array
    {
        return [
            '__key' => ApplyMenuArrangement::itemKey($dish->getKey()),
            'kind' => self::DISH,
            'id' => $dish->getKey(),
            'category_id' => $dish->menu_category_id,
            'depth' => $depth,
            'name' => $dish->name,
            'detail' => $dish->description,
            'type' => __('panel.items.dish'),
            'type_color' => 'gray',
            // Formatted here rather than in the browser: a panel is server
            // rendered, and the currency is resolved once for the page.
            'meta' => $dish->formattedPrice($currency),
            'state' => $dish->availability->label(),
            'state_color' => $dish->availability->color(),
            'url' => null,
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
        $isHeading = $record['kind'] !== self::DISH;
        $detail = filled($record['detail'])
            ? '<div style="font-size:0.75rem;opacity:0.65;margin-top:0.125rem">'.e((string) $record['detail']).'</div>'
            : '';

        return '<div style="padding-inline-start:'.$indent.'rem">'
            .'<div style="font-weight:'.($isHeading ? '600' : '400').'">'.e((string) $record['name']).'</div>'
            .$detail
            .'</div>';
    }

    private static function renameAction(Menu $menu, bool $mayManage): Action
    {
        return Action::make('rename')
            ->label(__('panel.arrangement.rename'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->visible(fn (array $record): bool => $mayManage && self::isCategoryRow($record))
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

    private static function createSubCategoryAction(Menu $menu, bool $mayManage): Action
    {
        return Action::make('createSubCategory')
            ->label(__('panel.sub_categories.create'))
            ->icon(Heroicon::OutlinedSquares2x2)
            // Two levels and no more, so only a top-level category holds one.
            ->visible(fn (array $record): bool => $mayManage && $record['kind'] === self::CATEGORY)
            ->schema(fn (Schema $schema): Schema => MenuSubCategoryForm::configure($schema, $menu->getKey()))
            ->fillForm(fn (array $record): array => ['parent_id' => $record['id']])
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
     * Carrying a whole section onto another of this restaurant's menus.
     *
     * An action rather than a select on the form for the reason in
     * .ai/rules/actions-menus.md: the page a category is renamed on *is* the
     * menu, so there is no "which menu" field to change, and this does one
     * thing no edit does — it unfeatures every dish in the branch.
     */
    private static function moveCategoryAction(Menu $menu, bool $mayManage): Action
    {
        return Action::make('moveToMenu')
            ->label(__('panel.categories.move'))
            ->icon(Heroicon::OutlinedArrowRightCircle)
            ->visible(fn (array $record): bool => $mayManage && $record['kind'] === self::CATEGORY)
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
                    // a clash is caught here rather than at the expression
                    // index after the form has passed.
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

    /**
     * Where a dish is actually edited: its own page, narrowed to this branch.
     */
    private static function openDishesAction(): Action
    {
        return Action::make('openDishes')
            ->label(fn (array $record): string => (string) ($record['kind'] === self::DISH
                ? __('panel.arrangement.open_dish')
                : __('panel.arrangement.open_dishes')))
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            // `filters`, not `tableFilters`: ListRecords binds the property as
            // `#[Url(as: 'filters')]`, so the other name arrives as an unread
            // query parameter and the page opens showing every dish there is.
            ->url(fn (array $record): string => MenuItemResource::getUrl('index', [
                'filters' => [
                    'menu_category_id' => ['value' => $record['category_id']],
                ],
            ]));
    }

    private static function deleteAction(Menu $menu, bool $mayManage): Action
    {
        return Action::make('delete')
            ->label(__('panel.arrangement.delete'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (array $record): bool => $mayManage && self::isCategoryRow($record))
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
     * The place at the end of a list, so something new lands where it is looked
     * for rather than at the top.
     *
     * @param  HasMany<MenuCategory, Menu>|HasMany<MenuCategory, MenuCategory>  $siblings
     */
    private static function nextPosition(HasMany $siblings): int
    {
        return ((int) $siblings->max('position')) + 1;
    }

    /**
     * The menus a category could be moved onto: this restaurant's, minus this one.
     *
     * @return array<int, string>
     */
    private static function otherMenus(Menu $menu): array
    {
        return array_diff_key(MenuCategoryForm::menuOptions(), [$menu->getKey() => null]);
    }
}
