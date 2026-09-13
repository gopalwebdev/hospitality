<?php

namespace App\Filament\Restaurant\Resources\Menus\Schemas;

use App\Filament\Schemas\TranslatedFields;
use App\Models\MenuCategory;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

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

    public static function configure(Schema $schema, ?int $menuId = null, ?MenuCategory $editing = null): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TranslatedFields::localeSwitcher(),

                Section::make(__('panel.sub_categories.section'))
                    ->icon(Heroicon::OutlinedSquares2x2)
                    ->schema([
                        // Only this menu's categories, and the composite
                        // foreign key refuses anything else even if the
                        // submitted id is tampered with.
                        Select::make('parent_id')
                            ->label(__('panel.categories.section'))
                            ->options(fn (): array => self::categoryOptions($menuId))
                            ->default(fn (): ?int => self::onlyCategoryKey($menuId))
                            ->required()
                            ->searchable()
                            ->preload()
                            ->live()
                            ->prefixIcon(Heroicon::OutlinedRectangleStack),

                        ...TranslatedFields::text(
                            'name',
                            __('panel.shared.name'),
                            maxLength: 64,
                            // Unique within the category, matching the
                            // expression index: two categories of one menu may
                            // each have a "Chicken".
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
     * The categories of one menu, in the order the restaurant arranged them.
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
     * Every category on one menu, at both levels, labelled by its branch.
     *
     * For the dishes filter once a menu has been picked: "Biryani" and
     * "Biryani › Chicken" are both places a dish can sit, and the menu is
     * already named by the filter beside it.
     *
     * @return array<int, string>
     */
    public static function categoryOptionsOnMenu(int $menuId): array
    {
        return once(fn (): array => MenuCategory::query()
            ->where('menu_id', $menuId)
            ->with('parent:id,name')
            ->inMenuOrder()
            ->get()
            ->mapWithKeys(fn (MenuCategory $category): array => [
                $category->getKey() => $category->path(),
            ])
            ->all());
    }

    /**
     * Every category this restaurant has, labelled with its menu.
     *
     * For the places that are not inside one menu — the dish form, which may
     * file a dish anywhere. Two menus may each have a "Starters", so the menu
     * has to be part of the label or the select offers the same word twice.
     *
     * @return array<int, string>
     */
    public static function categoryOptionsForRestaurant(?int $tenantId): array
    {
        // Both levels, because a dish may be filed at either — labelled with
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
