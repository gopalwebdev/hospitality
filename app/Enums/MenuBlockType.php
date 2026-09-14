<?php

namespace App\Enums;

/**
 * The kinds of thing on a menu's top level that are not a category.
 *
 * A menu is read as one sequence: its top-level categories, with these placed
 * among them. A category keeps its own `position`; a block keeps one on its
 * `menu_blocks` row, in the same number space, so both are dragged into one
 * order on the menu page.
 *
 * A kind a menu may hold several of — a banner, say — is a case here, its
 * columns on `menu_blocks` and a line in that table's constraints. The code that
 * orders a menu reads rows, not cases, and does not change.
 */
enum MenuBlockType: string
{
    /** The items a menu opens with — menu_items.is_featured, in featured_position order. */
    case Featured = 'featured';

    /** The bundles sold at one price — menu_combos. */
    case Combos = 'combos';

    /**
     * Whether every menu has exactly one, placed or not.
     *
     * One that nobody has placed has no row and reads at the top of the menu;
     * see Menu::readingOrder().
     */
    public function isOnEveryMenu(): bool
    {
        return match ($this) {
            self::Featured, self::Combos => true,
        };
    }

    /**
     * What the panel calls this block.
     */
    public function label(): string
    {
        $label = match ($this) {
            self::Featured => __('panel.items.featured_heading'),
            self::Combos => __('panel.combos.plural'),
        };

        return is_string($label) ? $label : $this->value;
    }
}
