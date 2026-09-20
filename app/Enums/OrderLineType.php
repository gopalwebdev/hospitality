<?php

namespace App\Enums;

/**
 * What one line of an order was ordered as: an item from a category, or a combo off the menu.
 *
 * The same two words a basket line carries (PriceBasket::ITEM, PriceBasket::COMBO),
 * and the one of `order_lines.menu_item_id` / `menu_combo_id` a line may fill.
 */
enum OrderLineType: string
{
    case Item = 'item';

    case Combo = 'combo';
}
