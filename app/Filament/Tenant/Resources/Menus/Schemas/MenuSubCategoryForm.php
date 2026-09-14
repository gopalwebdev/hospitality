<?php

namespace App\Filament\Tenant\Resources\Menus\Schemas;

use App\Filament\Schemas\TranslatedFields;
use App\Models\MenuCategory;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Naming a sub-category and saying which category it subdivides.
 *
 * Both levels are rows of menu_categories, so this form writes the same table
 * MenuCategoryForm does — it differs only in setting `parent_id`, which is what
 * makes a row a subdivision.
 *
 * Unlike MenuCategoryForm it *does* carry a parent select, because the page it
 * lives on is the menu rather than the category. Only this menu's top-level
 * categories are offered. Moving one afterwards is its own action, for the same
 * reason a category's move is.
 */
class MenuSubCategoryForm
{
    /**
     * The columns this form edits in more than one language.
     *
     * @var list<string>
     */
    public const array TRANSLATED = ['name'];

    /**
     * `$parentId` is the category a new sub-category starts under: the one its button was pressed on.
     */
    public static function configure(Schema $schema, ?int $menuId = null, ?MenuCategory $editing = null, ?int $parentId = null): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TranslatedFields::localeSwitcher(),

                Section::make(__('panel.sub_categories.section'))
                    ->icon(Heroicon::OutlinedSquares2x2)
                    ->schema([
                        // Only this menu's categories; MenuCategoryObserver
                        // refuses a parent on any other menu even if the
                        // submitted id is tampered with.
                        Select::make('parent_id')
                            ->label(__('panel.categories.section'))
                            ->options(fn (): array => self::categoryOptions($menuId))
                            ->default(fn (): ?int => $parentId ?? self::onlyCategoryKey($menuId))
                            ->required()
                            ->searchable()
                            ->preload()
                            ->live()
                            ->prefixIcon(Heroicon::OutlinedRectangleStack),

                        ...TranslatedFields::text(
                            'name',
                            __('panel.shared.name'),
                            maxLength: 64,
                            // Unique within the category: two categories of
                            // one menu may each have a "Chicken".
                            uniqueWithin: fn (Get $get): Builder => MenuCategory::query()
                                ->where('parent_id', $get('parent_id')),
                            uniqueMessage: __('panel.sub_categories.unique'),
                            editing: $editing,
                        ),
                    ])
                    ->columns(2),

                Section::make(__('panel.categories.on_the_menu'))
                    ->icon(Heroicon::OutlinedEye)
                    ->schema([
                        Toggle::make('is_active')
                            ->label(__('panel.sub_categories.is_active'))
                            ->default(true)
                            ->inline(false),
                    ]),
            ]);
    }

    /**
     * Put every language back into the form when a sub-category is edited.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillTranslations(array $data, MenuCategory $record): array
    {
        return $record->fillTranslationsInto($data, ...self::TRANSLATED);
    }

    /**
     * The categories of one menu, in the order the tenant arranged them.
     *
     * Every option list in this class is memoized for the request with once():
     * Filament asks a select for its options more than once while it builds and
     * validates one form.
     *
     * @return array<int, string>
     */
    public static function categoryOptions(?int $menuId): array
    {
        if ($menuId === null) {
            return [];
        }

        // Top level only: a menu is two levels deep, so a subdivision can only
        // ever be filed under a section.
        return once(fn (): array => MenuCategory::query()
            ->where('menu_id', $menuId)
            ->topLevel()
            ->inMenuOrder()
            ->get()
            ->mapWithKeys(fn (MenuCategory $category): array => [$category->getKey() => $category->name])
            ->all());
    }

    /**
     * Top-level categories for the items page's Category filter.
     *
     * Only the chosen menu's once one is picked, named alone because the menu
     * filter beside it already says which menu; every menu's otherwise, as
     * "Lunch · Starters", so two menus' "Starters" are told apart.
     *
     * @return array<int, string>
     */
    public static function topLevelOptions(?int $tenantId, ?int $menuId = null): array
    {
        $menus = $menuId === null ? MenuCategoryForm::menuOptions() : [];

        return self::tenantCategories($tenantId)
            ->filter(fn (MenuCategory $category): bool => $category->isTopLevel()
                && ($menuId === null || $category->menu_id === $menuId))
            ->mapWithKeys(fn (MenuCategory $category): array => [
                $category->getKey() => $menuId === null
                    ? sprintf('%s · %s', $menus[$category->menu_id] ?? '', $category->name)
                    : $category->name,
            ])
            ->all();
    }

    /**
     * Sub-categories for the items page's Sub-category filter.
     *
     * Narrowed to the category picked beside it, else to the chosen menu, else
     * every one the tenant has — each labelled with only as much of its branch
     * as the filters beside it have not already said.
     *
     * @return array<int, string>
     */
    public static function subCategoryOptions(?int $tenantId, ?int $menuId = null, ?int $parentId = null): array
    {
        $categories = self::tenantCategories($tenantId);
        $names = $categories->mapWithKeys(fn (MenuCategory $category): array => [$category->getKey() => $category->name]);
        $menus = $menuId === null && $parentId === null ? MenuCategoryForm::menuOptions() : [];

        return $categories
            ->filter(fn (MenuCategory $category): bool => $category->isSubCategory()
                && ($parentId === null || $category->parent_id === $parentId)
                && ($menuId === null || $category->menu_id === $menuId))
            ->mapWithKeys(function (MenuCategory $category) use ($names, $menus, $menuId, $parentId): array {
                $branch = sprintf('%s › %s', $names->get($category->parent_id, ''), $category->name);

                return [$category->getKey() => match (true) {
                    $parentId !== null => $category->name,
                    $menuId !== null => $branch,
                    default => sprintf('%s · %s', $menus[$category->menu_id] ?? '', $branch),
                }];
            })
            ->all();
    }

    /**
     * Every category the tenant has, at both levels, read once for the request.
     *
     * Both filter lists above are built from this one read, with menu names from
     * the list the Menu filter beside them has already loaded. Each list used to
     * eager-load the menus for itself, and the second load was the same query
     * the first had just run.
     *
     * @return EloquentCollection<int, MenuCategory>
     */
    private static function tenantCategories(?int $tenantId): EloquentCollection
    {
        return once(fn (): EloquentCollection => MenuCategory::query()
            ->select(['id', 'menu_id', 'parent_id', 'name', 'position'])
            ->where('tenant_id', $tenantId)
            ->inMenuOrder()
            ->get());
    }

    /**
     * Every category this tenant has, labelled with its menu.
     *
     * For the places that are not inside one menu — the item form, which may
     * file an item anywhere. Two menus may each have a "Starters", so the menu
     * has to be part of the label or the select offers the same word twice.
     *
     * @return array<int, string>
     */
    public static function categoryOptionsForTenant(?int $tenantId): array
    {
        // Both levels, because an item may be filed at either — labelled with
        // the menu and, for a subdivision, the section it sits under, so
        // "Lunch · Biryani › Chicken" reads as one place. Only the columns a
        // label needs are read from the menu and the parent.
        return once(fn (): array => MenuCategory::query()
            ->where('tenant_id', $tenantId)
            ->with(['menu:id,name', 'parent:id,name'])
            ->inMenuOrder()
            ->get()
            // menu_id is not nullable and cascades, so a category always has a
            // menu — there is nothing to fall back to here.
            ->mapWithKeys(fn (MenuCategory $category): array => [
                $category->getKey() => sprintf('%s · %s', $category->menu->name, $category->path()),
            ])
            ->all());
    }

    /**
     * The category to preselect when the menu has only one to choose.
     */
    private static function onlyCategoryKey(?int $menuId): ?int
    {
        $categories = self::categoryOptions($menuId);

        return count($categories) === 1 ? array_key_first($categories) : null;
    }
}
