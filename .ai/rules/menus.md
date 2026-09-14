---
paths:
  - 'app/Filament/Tenant/Resources/Menus/**'
---

# Menus

## A menu is two tabs, and the first is its outline
`MenuResource::getRecordSubNavigation()` puts two pages across the top of one menu record (`SubNavigationPosition::Top`): **Arrangement** (`ArrangeMenu`) and **Edit** (`EditMenu`: name and description beside showing and hours, two compact sections in a grid container, so they sit side by side on the page and stack in the narrower create modal).

There were four. Featured items and Combos were tabs of their own (`ManageMenuFeaturedItems`, `ManageMenuCombos`), and before those the page carried four relation managers. The Items page (`MenuItemResource`) stays as the flat list of every item across every menu, for finding one without knowing where it is filed.

## The arrangement is an outline, and what is inside a row opens in a modal
`MenuArrangementTable` lists, in the order a guest reads them, a menu's **blocks** (the featured items and the combos), its **categories**, and each category's **sub-categories** under it — and nothing else. A click on a row, or its Open button, opens a modal holding a table of what is inside it:

| Row | Table in the modal | Ordered by |
| --- | --- | --- |
| a category or sub-category | `CategoryItemsRelationManager`: its items — create, edit in a slide-over (add-ons and tax included), delete | `position` |
| Featured items | `FeaturedItemsRelationManager`: Feature items, edit, Remove from featured | `featured_position` |
| Combos | `CombosRelationManager`: create, edit, delete | `position` |

This replaced one table of every row on the menu, items, featured items and combos included. The project owner asked for it: a featured item could be dragged in among a category's items and looked filed there, and the note explaining why a drop was refused was one more thing on screen. **Do not put item rows back on the outline.**

The three are relation managers in `Menus/RelationManagers/` that **no resource lists**. They are deliberately not in `MenuResource::getRelations()`, which would draw them under the Edit tab too. A relation manager is exactly Filament's table of one record's related rows, with create, edit, delete and drag already bound to the model and its policy, so a relationship repeater (an item's add-ons, a combo's contents) works in them with no special handling. `MenuArrangementTable::contentsOf()` renders one through `Filament\Schemas\Components\Livewire`, keyed per row.

What the modal needs, all in `contentsModal()`:
- **`formWrapper(false)`.** Filament makes every action modal a `<form>`. The table inside opens modals of its own, which are forms too, and a browser's parser drops a form nested in a form — their save buttons would have submitted the outer modal instead.
- **No submit button** (`modalSubmitAction(false)`): every change inside is saved as it is made.
- **Nothing refreshes the outline while the modal is open.** Closing it calls `unmountAction()`, which re-renders the page in full, so the counts and the blocks drawn — a block emptied from inside its modal disappears — come from what is now saved. Filament resolves an open modal's row again on every render of the page, and a block that has just been emptied has no row to resolve.

**A block with nothing in it is not drawn.** The header carries **New category**, **Featured items** and **Combos**; the last two open the same modals as their rows, and are the way into a block that is still empty.

A row's actions are **separate icon buttons**, each named by a tooltip (`iconButton()`) and coloured by what it does: Open (primary), New sub-category (info, top-level categories only), Edit and Move to another menu (gray), Delete (danger). A block's row has only Open. They were one `ActionGroup::buttonGroup()` strip, which drew misaligned icons and a solid red delete button.

The outline is a Filament **custom data** table (`->records()`), because its rows are categories plus blocks that may not be rows anywhere yet. What follows from that:

- Records are plain arrays keyed by `__key` (`ArrayRecord::getKeyName()`), so `$record` in every action and column closure is an `array`. **Nothing in that file may be typed `Model`.** Keys are formatted by `ApplyMenuArrangement` so the table and the action that reads them cannot drift.
- `ArrangeMenu::reorderTable()` overrides Filament's, which writes one UPDATE over an Eloquent query there isn't one of. `reorderable('position', condition: ...)` still matters: it renders the handles and Filament short-circuits the write on the same call, which is `MenuCategoryPolicy::reorder()`.
- Actions are hand-built (`Action::make()->schema()->fillForm()->action()`), because `CreateAction`/`EditAction`/`DeleteAction` bind to a model. Each write closure loads the model and calls `Gate::authorize()` with it; visibility is answered **once per page** (`$mayManage`) rather than per row per action, which would be a query each. Open, Featured items and Combos are shown to anyone who may read the menu; the tables inside hide their own writes by policy.
- **A button that starts a row under a parent passes the parent as that field's default, never through `fillForm()`.** Filling a form with anything skips every other field's default: "New sub-category" filled with its parent left "showing" off, and an item filled with its category would lose its availability and diet. `MenuSubCategoryForm::configure(parentId:)` and `MenuItemForm::configure(categoryId:)` exist for this, and the items table passes its own category.
- A category form opened from here is handed the category it edits (`MenuCategoryForm::configure($schema, $menuId, $editing)`), because a schema's own `$record` is whatever surrounds it — for an action modal on a page, the page's **menu** — so the uniqueness rule cannot find the category to exclude on its own. See `.ai/rules/filament.md`.
- **The page redraws from saved rows after every action** (`ArrangeMenu::afterActionCalled()` flushes the cached records). Filament reads all the rows to find the one a row action is about and keeps that copy for the request, so without it a rename left the old name on screen and a deleted row stayed in the list until a reload.

**Three queries build the outline, however big the menu**: categories at both levels with a count of their items, the menu's placed blocks, and one query counting its combos and its featured items. `MenuCategoryTreeTest` asserts neither the page's query count nor the featured items table's grows with the menu.

The items table creates through `MenuItem::query()->create()` rather than through the relationship, because the item form keeps its category select: created through the relationship, a different choice there would be silently overruled.

## Rows are tinted by kind, and a drag is refused outside a row's own list
`recordClasses()` puts `menu-row--<kind>` (`featured`, `combos`, `category`, `sub_category`) and `menu-list--<list>` on every row. `resources/views/filament/tenant/resources/menus/pages/arrange-menu.blade.php` holds what reads them, inline because a panel ships no CSS of ours: a tint per kind on the row and a coloured edge on its first cell, mixed from Filament's colour variables so dark mode follows, with the row's Kind badge in the same colour.

**Every kind has a colour of its own**, as the project owner asked: amber (`warning`) for Featured items, green (`success`) for Combos, violet for a category and blue (`info`) for a sub-category. Categories were first `primary`, and the tenant panel's primary is amber, so they read as featured items. Violet is not a Filament default: `TenantPanelProvider` registers it in `->colors()`, which is what defines `--violet-500` and lets a badge be `->color('violet')`.

Filament draws a table's rows as **`tr.fi-ta-row`**. The styles once targeted `.fi-ta-record`, which is the grid layout's class, and tinted nothing. The edge sits on the first cell because not every browser draws a box-shadow on a table row, and hover is a gradient laid over the tint because the tint replaces Filament's own hover colour.

The guard is a Livewire `@script`. Filament's drag and drop is SortableJS, which exists as `sortable` on the `[x-sortable]` list only in reorder mode, so a MutationObserver waits for it and then sets:
- `onStart`, which dims every row outside the dragged row's list
- `onMove`, which refuses a row in another list and flashes it
- a wrapper around Filament's own `onEnd`, which must still be called — it is what puts the dropped node back where Sortable says it went

It guards only the outline's own list (`closest('[wire\\:id]')` is the page), never the tables inside a modal. There is no note saying why a drop was refused: the project owner had it removed.

The `list` values — `top` and `sub-<parent id>` — mirror the lists `ApplyMenuArrangement` renumbers. Change one and change the other; `MenuCategoryTreeTest` pins them. The server still puts a stray row back among its own siblings, because the guard is only in the browser.

## Blocks are what sits on a menu's top level beside its categories
`menu_blocks` holds a menu's top level that is not a category: `type` is `App\Enums\MenuBlockType` (`Featured`, `Combos`), and `position` shares one number space with the top-level `menu_categories.position`, so moving a block is the same drag as moving a category. `Menu::readingOrder($categories, $blocks)` merges the two, and both the panel and `Guest\MenuController` read it — the guest app is *sent* the order (`order`, a list of `'featured' | 'combos' | <section id>`), because what a guest reads first is a decision and decisions stay in PHP.

**A block every menu has (`MenuBlockType::isOnEveryMenu()`) has no row until it is placed.** Without one it reads at position 0, and ties break blocks first, in enum order — so a menu nobody has arranged opens with its featured items, then its combos, then its categories. `ApplyMenuArrangement` saves the row the first time a drag puts the block anywhere else. Nothing creates rows when a menu is created, which is why the seeder (which runs without model events) and the factories need nothing.

This replaced `menus.featured_position` and `menus.combos_position`, a column per rail, because the project owner wants menus to grow new kinds of content — a banner image with or without text over it was the example given — and a column per kind cannot hold several banners. **Adding a kind** is a case on `MenuBlockType` (false from `isOnEveryMenu()` if a menu may hold several), its columns on `menu_blocks` with a CHECK tying them to the type the way `home_tiles` ties a destination to its action, a form, its row on the outline and what its row opens, and a component in the guest app. `readingOrder()` and `ApplyMenuArrangement` read rows rather than cases and do not change; a block that is not on every menu is keyed `block-<id>`.

What a block *holds* stays where it was: featured items are `menu_items.is_featured` in `featured_position` order, and combos are `menu_combos` on the menu.

## Featured items are one flag, set from the item form or the featured items table
`menu_items.is_featured` plus `featured_position` are what a menu leads with. The **Featured** toggle on the item form sets it, and so does **Feature items** in the featured items table, which offers this menu's items that are not featured yet and puts the chosen ones at the end of the featured order, in the order picked. **Remove from featured** clears the flag and the position. See `.ai/rules/actions-menus.md` for why there are exactly two.

`featured_position` is deliberately separate from `position`, which orders an item inside its category. An item answers both at once — it is a row in its category's table and in the featured items table — and the two orders are unrelated. The featured items table hangs off `Menu::menuItems()`, a HasManyThrough, so it overrides `reorderTable()` to update `menu_items` alone (`.ai/rules/tables.md`).

Featuring belongs to one menu, so an item carried to a category on **another** menu is unfeatured on the way — by `MenuItemObserver` for a single item and by `MoveCategoryToMenu` for a whole branch. Without that, an item would appear at the top of a menu nobody had chosen it for, at whatever `featured_position` it happened to hold.

## Combos hang off the menu, and are priced on their own
`menu_combos` sits beside the featured items rather than under a category: a combo is something a menu leads with, not something in a category, and "which category does a burger meal belong to" is a question with no answer worth having.

Its price is typed, never derived from `menu_combo_items`. The whole point of a combo is that it costs less than the sum of its parts, so a derived price would either be that sum or a discount rule nobody asked for — `MenuCombo::contentsPriceMinorUnits()` exists only to show the saving beside the price, never to set it. Repricing an item therefore never silently reprices a combo.

What goes in a combo is an **item**, never a service request — a laundry pickup is asked for, not sold in a bundle. `MenuComboForm::itemOptions()` offers this menu's items grouped under the category each is filed in, in menu order, so each option is the item's name alone; the project owner found "Tiffin › Dosa · Masala Dosa" on every line hard to read. A single select's own `in` validation looks inside groups, so an id the picker did not offer is refused (`MenuComboTest`).

## The menus list is not ordered by hand
`menus` has no `position`, on the project owner's instruction: menus are not rearranged, the things inside a menu are. A guest reaches a menu through a home screen tile, so nothing reads the order of the menus list but an admin scanning it. `MenusTable` sorts by name through `TranslatedFields::sort()`, `Menu::scopeByName()` orders the option lists that name menus, and `MenuPolicy` has no `reorder()`.
