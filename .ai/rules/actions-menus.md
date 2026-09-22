---
paths:
  - 'app/Actions/Menus/**'
  - 'app/Actions/Baskets/**'
---

# Menu and basket actions

## A category can move between a tenant's menus, everything under it and all
`MoveCategoryToMenu` rewrites `menu_categories.menu_id` on the category and on its sub-categories, then unfeatures the items under both, in one transaction. Items follow untouched because they carry `menu_category_id`, not `menu_id`. Sub-categories carry `menu_id` too; they used to follow by an `ON UPDATE CASCADE` on a composite key, which the schema no longer has (`.ai/rules/migrations.md`), so the action moves them itself.

The unfeaturing is the one thing it does beyond the move: the featured items belong to a menu, so items that have just left one must not appear at the top of the one they arrived on. `MenuItemObserver` applies the same rule to a single item, and has to, because moving a category changes no item's own columns.

Both of its guards are backstops, thrown as LogicException: the target menu must belong to the same tenant (`MenuCategoryObserver` would refuse anyway, but as a 500), and the English name must be free on the target (uniqueness is per menu, and nothing else refuses a duplicate). `MenuArrangementTable`'s `moveToMenu` action states both as validation, which is what an admin actually sees — the action is what stops code going around the panel.

## One drag renumbers the outline, and never re-parents anything
`ApplyMenuArrangement` takes the flat order Filament hands back from the menu page — its rails, categories and sub-categories together — and turns it into the positions a menu stores: `menu_rails.position` and `menu_categories.position`. What is inside a category or a rail is not on that page. It is ordered in the table the row opens, by Filament's own reorder (`.ai/rules/menus.md`), so this action no longer touches `menu_items` or `menu_combos`.

**A row only moves within its own list.** The lists are the top level (rails and top-level categories, ordered against each other) and the sub-categories of one category. A sub-category dropped under another category keeps the parent it had and lands at the matching place among its own siblings, so no drag can produce a menu that could not exist.

That is deliberate, and it is the rule below rather than a limitation of the drag: re-filing a subdivision is an edit on its own form, where the parent is a select and the name is revalidated against where it is going. A drag that re-parented would be a second mechanism repeating that uniqueness rule, and getting it wrong would store a duplicate name that nothing else refuses.

**A row the table did not draw keeps its place.** An empty rail is not drawn, so it is missing from the order Filament sends. `renumber()` shuffles only the rows that were sent, among the slots they already held, and does not write a list none of whose rows were sent. Before this, a row missing from the order sorted last, which would have pushed an empty featured rail to the bottom of the menu whenever anything else was dragged. A rail every menu has but nobody has placed is an unsaved `MenuRail` reading at 0, and is saved the first time it lands anywhere else.

Row keys (`featured`, `combos`, `rail-<id>`, `category-<id>`) are formatted by this class as well as parsed by it, so the table that renders them cannot drift from the action that reads them.

## Re-parenting is an edit, not an action — MoveCategoryToMenu is the exception
A record's parent is chosen on its own form. There is no `MoveSubCategoryToParent` and no `MoveItemToCategory`; both existed, both were deleted, and the reason is worth keeping: each was a second mechanism that had to repeat rules the form already enforces.

- A **sub-category** is re-parented by editing it and picking another category. The form offers only this menu's top-level categories, so "same menu" and "no third level" are enforced by the options rather than by a guard, and the uniqueness rule is scoped to the chosen parent so changing it revalidates the name against where it is going.
- An **item** is re-filed by editing it and picking another category. That select offers both levels of every menu in the tenant, so an item can cross menus; its uniqueness rule is scoped to the chosen category the same way.
- An **option** cannot move between add-on groups. It is edited in the options repeater of the group that owns it, and `MenuAddOnOptionObserver` refuses one whose group belongs to another tenant.
- A **combo's contents** likewise: a repeater inside one combo, never dragged to another.

`MoveCategoryToMenu` survives because it is the one case a form cannot express. Categories are edited on the menu page, so there is no "which menu" select to change — the page *is* the menu. It also does something no edit does: it unfeatures every item in the branch, subdivisions included.

## Featuring is one flag, set from the item form or the featured items table
`menu_items.is_featured` is set by the item form's Featured toggle and by **Feature items** and **Remove from featured** in `FeaturedItemsRelationManager` — the table the menu page's Featured items row opens — and nowhere else. The old featured tab carried its own `feature` and `unfeature` actions and they were deleted as a second mechanism for one flag; the project owner later asked to feature items from the menu page, and that table is the one other place. Those actions write the flag and `featured_position` and nothing else.

The invariant that an item leaving a menu stops being featured lives in `MenuItemObserver` rather than in whatever writes the item, because it has to hold however the item is written — including from a form that has just been told `is_featured` is true. `MoveCategoryToMenu` still unfeatures its branch itself, because moving a category changes no item's `menu_category_id` and the observer cannot see it.

## PriceBasket prices a basket kept on the phone
`PriceBasket` is what `POST /menus/{menu}/basket-prices` answers (`Guest\BasketPriceController`, shape checked by `PriceBasketRequest`). The basket is the guest's claim, so every line is read again against the menu as it is now — orderable items on this menu with their linked groups and available options, and orderable combos — and priced only if it still stands. Arithmetic and rules are here and not in the browser, which only formats the answer.

- A line comes back **`unavailable`** when its item or combo is gone, sold out, on another menu or another tenant's, or when a required group has no available option left — the same decision `Guest\MenuController` makes to leave the item off the menu.
- It comes back **`invalid`** when an option is not an available option of one of the item's groups, when more of one is asked for than its own `max_per_item` allows (`MenuAddOnGroup::quantityAllowedFor()`, never more than the maximum picks in play for that item), when a required group has no pick or a group's picks, each counted by quantity, go over that same maximum — the linked item's own `max_picks`, or the group's own when the item has none (`MenuItemAddOnGroup::effectiveMaxPicks()`) — or when the basket holds more of an item or combo than one order may (`max_per_order`). That last count is across every line the item or combo is on, so it flags every one of those lines.
- A flagged line is priced at nothing and the rest of the basket is still priced.

GST is worked out part by part and rounded once per part per line:
- **Items:** every part of an item's line is taxed at the item's rate, its options included. An add-on is part of the item it is added to, a composite supply taxed at the rate of its principal supply (CGST Act, s. 8(a)).
- **Combos:** taxed at their own rate.
- **Charges are taxed too**, at the tenant's own rate — a service charge is consideration for the same supply, not something added after tax. The tenant's rate rather than an item's, because a bill spanning several slabs has no one principal supply to follow.

**Every amount carries its split.** `App\Actions\Baskets\GstSplit` is where the halves are worked out, once, and it is the only place that arithmetic lives: the rate halves in basis points (`intdiv`, remainder to the state) so the two always add to the rate, and **each half's amount is worked out from its own rate**. The tax charged is what the two come to, not a figure worked out at the whole rate and then divided.

That is the fix for a real bill, and the reason it must not be undone: CGST and SGST are separate levies on one taxable value — s. 9 of the CGST Act, s. 7 of the UTGST Act — so an equal rate has to produce an equal amount. Taking the whole rate first and giving one side the remainder broke that on any amount whose tax was an odd number of paise. A ₹50.00 room service fee carrying 18% comes to ₹7.63, and the guest's basket read "CGST 9% ₹3.81" beside "UTGST 9% ₹3.82" — an amount neither 9% nor the taxable value printed beside it produces. Rounding each side for itself moves at most a paisa of tax and never moves the total a guest pays. A priced basket carries `taxParts` (a `GstSplit`) beside `tax` (the one number) at the top level, per line and per charge; `PlaceOrder` copies them onto `orders`, `order_lines` and `order_charges`. Never re-derive a split from a stored total: halving it does not reliably add back up. There is no IGST: every bill is CGST plus the state's half, and `tenant_settings.is_union_territory` decides only whether that half reads UTGST (`.ai/rules/enums.md`). A priced basket carries it as `isUnionTerritory`, beside `pricesIncludeTax`.

With `prices_include_tax` it is the share already inside the price and is not added to the total. Charges are `Charge::amountOn()` on the subtotal, and an empty basket carries none. The queries do not grow with the basket.

Beside the lines, a priced basket carries `shortages`: what the lines that stand would take from a counted item or option with fewer left, read without a lock, in the shape `InsufficientStock` renders. A line's status does not change for it — an item with none left is already out of stock, and so already `unavailable`.

Placing an order (`App\Actions\Orders\PlaceOrder`) prices the basket here first and refuses it when any line is not `ok`, then takes stock under a lock — see `.ai/rules/inventory.md`. Tests: `tests/Feature/Tenant/BasketPriceTest.php`, `tests/Feature/Tenant/PlaceOrderTest.php`.
