<?php

namespace App\Actions\Menus;

use App\Enums\Locale;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Move a top-level category onto another of the same tenant's menus, with its
 * sub-categories and every item under both.
 *
 * Items follow on their own because they hang off a category; sub-categories
 * carry menu_id, so they are moved with it. Items that were featured stop
 * being featured, because the featured row belongs to a menu.
 *
 * The guards are backstops: MenuArrangementTable states the same rules as
 * validation, so the panel never reaches them.
 */
class MoveCategoryToMenu
{
    /**
     * @throws LogicException when the target menu belongs to another tenant, the
     *                        category is a sub-category, or the name is taken there
     */
    public function __invoke(MenuCategory $category, Menu $target): void
    {
        if ($category->menu_id === $target->getKey()) {
            return;
        }

        throw_if(
            $target->tenant_id !== $category->tenant_id,
            LogicException::class,
            'A category may only move to a menu of its own tenant.',
        );

        throw_if(
            $category->isSubCategory(),
            LogicException::class,
            'A sub-category moves between the categories of its menu, not between menus.',
        );

        throw_if(
            self::nameIsTakenOn($category, $target->getKey()),
            LogicException::class,
            'That menu already has a category with this name.',
        );

        DB::transaction(function () use ($category, $target): void {
            $category->update(['menu_id' => $target->getKey()]);

            MenuCategory::query()
                ->where('parent_id', $category->getKey())
                ->update(['menu_id' => $target->getKey()]);

            MenuItem::query()
                ->where('is_featured', true)
                ->where(fn (Builder $inBranch): Builder => $inBranch
                    ->where('menu_category_id', $category->getKey())
                    ->orWhereIn('menu_category_id', MenuCategory::query()
                        ->select('id')
                        ->where('parent_id', $category->getKey())))
                ->update(['is_featured' => false, 'featured_position' => 0]);
        });
    }

    /**
     * Whether the target menu already has a top-level category of this name.
     *
     * Asked by the arrangement table as validation and again here in the same
     * request, so it is answered once.
     */
    public static function nameIsTakenOn(MenuCategory $category, int $menuId): bool
    {
        $name = $category->getTranslation('name', Locale::default()->value);

        return once(fn (): bool => MenuCategory::query()
            ->withoutGlobalScopes()
            ->where('menu_id', $menuId)
            ->whereNull('parent_id')
            ->where('name->'.Locale::default()->value, $name)
            ->exists());
    }
}
