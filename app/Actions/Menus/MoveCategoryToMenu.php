<?php

namespace App\Actions\Menus;

use App\Enums\Locale;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Move a category, and everything in it, onto another of the same
 * tenant's menus.
 *
 * A tenant that splits one card into a lunch and a dinner menu wants to
 * carry a whole section across rather than retype it. Its subdivisions follow
 * by the ON UPDATE CASCADE on the (parent_id, menu_id) key, and the dishes
 * follow because they hang off a category rather than off a menu.
 *
 * This is the only way a subdivision ever changes menus: its parent moves, and
 * it follows.
 *
 * Two guards, both backstops: MenuArrangementTable states the same rules as
 * validation, so the panel never reaches these. The target menu has to belong
 * to the same tenant — the composite foreign key would refuse otherwise,
 * but only as a 500 — and the name has to be free on the target, because
 * uniqueness is per menu and the expression index would reject the update
 * after the form had already passed.
 */
class MoveCategoryToMenu
{
    /**
     * @throws LogicException when the target menu belongs to another
     *                        tenant, or already has this name on it
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

        // The subdivisions under it are carried across by the ON UPDATE
        // CASCADE on (parent_id, menu_id); the dishes follow because they carry
        // menu_category_id rather than menu_id.
        $category->update(['menu_id' => $target->getKey()]);

        // Featuring is per menu — the featured row is "what *this* menu leads
        // with" — so a dish that has just left a menu cannot still be at the
        // top of it, and must not silently appear at the top of the one it
        // arrived on. It stays on the menu under this category; only the
        // leading-with stops. MoveItemToCategory applies the same rule to a
        // single dish. Every dish in the branch counts, which means the
        // subdivisions' dishes too — they moved menus just as surely.
        MenuItem::query()
            ->where('is_featured', true)
            ->where(fn (Builder $inBranch): Builder => $inBranch
                ->where('menu_category_id', $category->getKey())
                ->orWhereIn('menu_category_id', MenuCategory::query()
                    ->select('id')
                    ->where('parent_id', $category->getKey())))
            ->update(['is_featured' => false, 'featured_position' => 0]);
    }

    /**
     * Whether the menu a category is moving to already has a section of its name.
     *
     * Uniqueness is per level, so only the target's own top-level sections
     * count. The arrangement table asks this as validation and this action asks
     * it again as a backstop, in the same request, so it is answered once.
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
