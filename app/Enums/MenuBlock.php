<?php

namespace App\Enums;

use App\Models\Menu;

/**
 * The two things on a menu that are not a category.
 *
 * A menu is read as a sequence of blocks: the items it leads with, the combos
 * it sells together, and its categories. The categories carry their own
 * `position`; these two carry theirs on the menu itself, in the same number
 * space, so all three can be dragged into one order on the arrangement screen.
 *
 * `positionColumn()` is the single place that says which column belongs to
 * which rail — the same shape as HomeTileAction::targetColumn(). Adding a third
 * rail means a case, a column and a line there, and nothing else.
 */
enum MenuBlock: string
{
    /** The items a menu opens with — menu_items.is_featured, in featured_position order. */
    case Featured = 'featured';

    /** The bundles sold at one price — menu_combos. */
    case Combos = 'combos';

    /**
     * The column on `menus` holding where this rail sits.
     */
    public function positionColumn(): string
    {
        return match ($this) {
            self::Featured => 'featured_position',
            self::Combos => 'combos_position',
        };
    }

    /**
     * Where this rail sits on a given menu.
     */
    public function positionOn(Menu $menu): int
    {
        return (int) $menu->getAttribute($this->positionColumn());
    }

    /**
     * What the panel calls this rail.
     */
    public function label(): string
    {
        $label = match ($this) {
            self::Featured => __('panel.items.featured_heading'),
            self::Combos => __('panel.combos.plural'),
        };

        return is_string($label) ? $label : $this->value;
    }

    /**
     * What the panel says this rail is, under its name.
     */
    public function description(): string
    {
        $description = match ($this) {
            self::Featured => __('panel.arrangement.featured_description'),
            self::Combos => __('panel.arrangement.combos_description'),
        };

        return is_string($description) ? $description : '';
    }
}
