---
paths:
  - 'app/Actions/Menus/**'
---

# Actions Menus

## A category can move between a tenant's menus, everything under it and all
`MoveCategoryToMenu` rewrites `menu_categories.menu_id`, and then unfeatures the dishes under it. Its sub-categories and dishes follow untouched because they carry `menu_category_id`, not `menu_id` — a category is the only thing that knows which menu it is on.

The unfeaturing is the one thing it does beyond the move: the featured row belongs to a menu, so dishes that have just left one must not appear at the top of the one they arrived on. `MenuItem::booted()` applies the same rule to a single dish, and has to, because moving a category changes no dish's own columns.

Both of its guards are backstops, thrown as LogicException: the target menu must belong to the same tenant (the composite `(menu_id, tenant_id)` foreign key would refuse anyway, but as a 500), and the English name must be free on the target (uniqueness is per menu, so the expression index would reject the update after the form had passed). `MenuArrangementTable`'s `moveToMenu` action states both as validation, which is what an admin actually sees — the action is what stops code going around the panel.

## One drag renumbers the menu, and never re-parents anything
`ApplyMenuArrangement` takes the flat order Filament hands back from the arrangement table — every row of the menu, rails, categories, subdivisions and dishes together — and turns it into the four kinds of `position` a menu stores: the two columns on `menus`, and `position` on `menu_categories` and `menu_items`.

**A row only moves within its own list.** The lists are the top level (both rails and the categories, ordered against each other), the dishes of one category, and the subdivisions of one category. A row dropped into another branch keeps the parent it had and lands at the matching place among its own siblings, so no drag can produce a menu that could not exist.

That is deliberate, and it is the rule below rather than a limitation of the drag: re-filing a dish or a subdivision is an edit on its own form, where the parent is a select and the name is revalidated against where it is going. A drag that re-parented would be a second mechanism repeating that uniqueness rule, and getting it wrong means the expression unique index refuses the write as a 500 rather than as a message.

Row keys (`featured`, `combos`, `category-<id>`, `item-<id>`) are formatted by this class as well as parsed by it, so the table that renders them cannot drift from the action that reads them.

## Re-parenting is an edit, not an action — MoveCategoryToMenu is the exception
A record's parent is chosen on its own form. There is no `MoveSubCategoryToParent` and no `MoveItemToCategory`; both existed, both were deleted, and the reason is worth keeping: each was a second mechanism that had to repeat rules the form already enforces.

- A **sub-category** is re-parented by editing it and picking another category. The form offers only this menu's top-level categories, so "same menu" and "no third level" are enforced by the options rather than by a guard, and the uniqueness rule is scoped to the chosen parent so changing it revalidates the name against where it is going.
- A **dish** is re-filed by editing it and picking another category. That select offers both levels of every menu in the tenant, so a dish can cross menus; its uniqueness rule is scoped to the chosen category the same way.
- An **addition** cannot move at all. It is edited in a repeater inside the dish that owns it, and the composite `(menu_item_id, tenant_id)` key makes that structural rather than a convention.
- A **combo's contents** likewise: a repeater inside one combo, never dragged to another.

`MoveCategoryToMenu` survives because it is the one case a form cannot express. Categories are edited on the menu's arrangement screen, so there is no "which menu" select to change — the page *is* the menu. It also does something no edit does: it unfeatures every dish in the branch, subdivisions included.

## Featuring has exactly one home
`menu_items.is_featured` is set by the dish's own form and nowhere else. The featured rail used to carry `feature` and `unfeature` actions that set the same flag; that was the same duplication as the move actions and went the same way. The row now exists to **put dishes in order**, which is the one thing the dish form cannot do.

The invariant that a dish leaving a menu stops being featured lives in `MenuItem::booted()` rather than in whatever writes the dish, because it has to hold however the dish is written — including from a form that has just been told `is_featured` is true. `MoveCategoryToMenu` still unfeatures its branch itself, because moving a category changes no dish's `menu_category_id` and the model hook cannot see it.
