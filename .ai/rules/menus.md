---
paths:
  - 'app/Filament/Tenant/Resources/Menus/**'
---

# Menus

## A menu is two tabs, and the first is the whole menu, edited where it sits
`MenuResource::getRecordSubNavigation()` puts two pages across the top of one menu record (`SubNavigationPosition::Top`): **Arrangement** (`ArrangeMenu`) and **Edit** (`EditMenu`: name and description beside showing and hours, two compact sections in a grid container, so they sit side by side on the page and stack in the narrower create modal).

There were four. Featured items and Combos were tabs of their own (`ManageMenuFeaturedItems`, `ManageMenuCombos`), and before those the page carried four relation managers. The project owner asked for one page where the whole menu is seen and every row on it is added, edited and deleted in place, so both tabs were deleted and their jobs moved onto the arrangement. The Items page (`MenuItemResource`) stays as the flat list of every item across every menu, for finding one without knowing where it is filed.

## The arrangement is one table of every row on the menu
`MenuArrangementTable` lists, in the order a guest reads them: each **block** (the featured items and the combos, with what is in each listed under it), every **category**, every **sub-category** under its category, and every **item** under the heading it is filed on. Indentation is the tree — `nameHtml()` pads by depth — and one drag moves anything within its own list.

**A block with nothing in it is not drawn.** An empty "Featured items · No items" row used to head every menu. The header carries **New category**, **New combo** and **Feature items**, which is how a block is started; once it holds something it appears where it was placed.

Every action a row has is one strip of icon buttons — `ActionGroup::buttonGroup()`, each action passed through `iconButton()`, which hides its label and shows it as a tooltip — so nothing sits behind a ⋯ menu, and a click on the row opens its edit (`recordAction()`):
- a category: Add item, Add sub-category, Edit, Move to another menu, Delete; a sub-category the same without Add sub-category and Move
- the featured block: Feature items; each featured item: Edit, Remove from featured
- the combos block: New combo; each combo: Edit, Delete
- an item: Edit, Delete

It is a Filament **custom data** table (`->records()`), because its rows are several models plus blocks that may not be rows anywhere yet. What follows from that:

- Records are plain arrays keyed by `__key` (`ArrayRecord::getKeyName()`), so `$record` in every action and column closure is an `array`. **Nothing in this file may be typed `Model`.** Keys are formatted by `ApplyMenuArrangement` so the table and the action that reads them cannot drift.
- `ArrangeMenu::reorderTable()` overrides Filament's, which writes one UPDATE over an Eloquent query there isn't one of. `reorderable('position', condition: ...)` still matters: it renders the handles and Filament short-circuits the write on the same call, which is `MenuCategoryPolicy::reorder()`.
- Actions are hand-built (`Action::make()->schema()->fillForm()->action()`), because `CreateAction`/`EditAction`/`DeleteAction` bind to a model. Each write closure loads the model and calls `Gate::authorize()` with it; visibility is answered **once per page** (`$mayManage`) rather than per row per action, which would be a query each. A header action and a row action cannot share a name, so the two ways to start a block are `createCombo`/`addCombo` and `featureItems`/`addFeaturedItems`, each pair built by one method.
- **Items and combos are edited here, in a slide-over, and both forms carry a repeater bound to a relationship.** A repeater needs a real record behind its schema and a row is an array, so the action hands the schema the model — `MenuItemForm::configure($schema->model($item))` — before it is filled, and saves with `$schema->model($record)->saveRelationships()`, which is what Filament's own `CreateAction` does. Without the model the repeater loads nothing, and saving deletes every add-on or combo line. The tests that edit an item and a combo **without touching the repeater** and assert its rows survive are what pin this. The item form's Tax and Add-ons sections render only once opened (`deferLoading()`); their state is filled and saved with the rest either way.
- **A button that starts a row under a parent passes the parent as that field's default, never through `fillForm()`.** Filling a form with anything skips every other field's default: "Add item" filled with its category would leave availability and diet empty, and "New sub-category" filled with its parent left "showing" off. `MenuItemForm::configure(categoryId:)` and `MenuSubCategoryForm::configure(parentId:)` exist for this.
- A category form opened from here is handed the category it edits (`MenuCategoryForm::configure($schema, $menuId, $editing)`), because a schema's own `$record` is whatever surrounds it — for an action modal on a page, the page's **menu** — so the uniqueness rule cannot find the category to exclude on its own. See `.ai/rules/filament.md`. Items and combos are spared this by being handed their model.
- **The page redraws from saved rows after every action** (`ArrangeMenu::afterActionCalled()` flushes the cached records). Filament reads all the rows to find the one a row action is about and keeps that copy for the request, so without it a rename left the old name on screen and a deleted row stayed in the list until a reload.

**Four queries build the rows, however big the menu**: categories at both levels with their items, the menu's placed blocks, and its combos with a count of their contents. Featured rows are picked out of the items already loaded. `MenuCategoryTreeTest` asserts the page's query count does not grow with the menu, so a per-row lookup fails it.

## Rows are tinted by kind, and a drag is refused outside a row's own list
`recordClasses()` puts `menu-row--<kind>` and `menu-list--<list>` on every row. `resources/views/filament/tenant/resources/menus/pages/arrange-menu.blade.php` holds what reads them, inline because a panel ships no CSS of ours: a tint and an edge colour per kind, mixed from Filament's own `--primary-500` / `--warning-500` / `--info-500` variables so dark mode follows, and the drag guard.

The guard is a Livewire `@script`. Filament's drag and drop is SortableJS, which exists as `sortable` on the `[x-sortable]` list only in reorder mode, so a MutationObserver waits for it and then sets `onStart` (dim every row outside the dragged row's list and show a note at the foot of the screen saying why), `onMove` (refuse a row in another list, and flash it) and a wrapper around Filament's own `onEnd`, which must still be called — it is what puts the dropped node back where Sortable says it went. The project owner asked for this: a drop into the wrong category used to be accepted on screen and quietly undone by the server.

The `list` values — `top`, `featured`, `combos`, `sub-<parent id>`, `items-<category id>` — mirror the lists `ApplyMenuArrangement` renumbers. Change one and change the other; `MenuCategoryTreeTest` pins them. The server still puts a stray row back among its own siblings, because the guard is only in the browser.

## Blocks are what sits on a menu's top level beside its categories
`menu_blocks` holds a menu's top level that is not a category: `type` is `App\Enums\MenuBlockType` (`Featured`, `Combos`), and `position` shares one number space with the top-level `menu_categories.position`, so moving a block is the same drag as moving a category. `Menu::readingOrder($categories, $blocks)` merges the two, and both the panel and `Guest\MenuController` read it — the guest app is *sent* the order (`order`, a list of `'featured' | 'combos' | <section id>`), because what a guest reads first is a decision and decisions stay in PHP.

**A block every menu has (`MenuBlockType::isOnEveryMenu()`) has no row until it is placed.** Without one it reads at position 0, and ties break blocks first, in enum order — so a menu nobody has arranged opens with its featured items, then its combos, then its categories. `ApplyMenuArrangement` saves the row the first time a drag puts the block anywhere else. Nothing creates rows when a menu is created, which is why the seeder (which runs without model events) and the factories need nothing.

This replaced `menus.featured_position` and `menus.combos_position`, a column per rail, because the project owner wants menus to grow new kinds of content — a banner image with or without text over it was the example given — and a column per kind cannot hold several banners. **Adding a kind** is a case on `MenuBlockType` (false from `isOnEveryMenu()` if a menu may hold several), its columns on `menu_blocks` with a CHECK tying them to the type the way `home_tiles` ties a destination to its action, a form, its row kind and actions here, and a component in the guest app. `readingOrder()` and `ApplyMenuArrangement` read rows rather than cases and do not change; a block that is not on every menu is keyed `block-<id>`.

What a block *holds* stays where it was: featured items are `menu_items.is_featured` in `featured_position` order, and combos are `menu_combos` on the menu.

## Featured items are one flag, set from the item form or the menu page
`menu_items.is_featured` plus `featured_position` are what a menu leads with. The **Featured** toggle on the item form sets it, and so does **Feature items** on the menu page, which offers this menu's items that are not featured yet and puts the chosen ones at the end of the featured order, in the order picked. **Remove from featured** clears the flag and the position. See `.ai/rules/actions-menus.md` for why there are exactly two.

`featured_position` is deliberately separate from `position`, which orders an item inside its category. An item answers both at once — and is a row in both lists on the page — and the two orders are unrelated.

Featuring belongs to one menu, so an item carried to a category on **another** menu is unfeatured on the way — by `MenuItemObserver` for a single item and by `MoveCategoryToMenu` for a whole branch. Without that, an item would appear at the top of a menu nobody had chosen it for, at whatever `featured_position` it happened to hold.

## Combos hang off the menu, and are priced on their own
`menu_combos` sits beside the featured items rather than under a category: a combo is something a menu leads with, not something in a category, and "which category does a burger meal belong to" is a question with no answer worth having.

Its price is typed, never derived from `menu_combo_items`. The whole point of a combo is that it costs less than the sum of its parts, so a derived price would either be that sum or a discount rule nobody asked for — `MenuCombo::contentsPriceMinorUnits()` exists only to show the saving beside the price, never to set it. Repricing an item therefore never silently reprices a combo.

## The menus list is not ordered by hand
`menus` has no `position`, on the project owner's instruction: menus are not rearranged, the things inside a menu are. A guest reaches a menu through a home screen tile, so nothing reads the order of the menus list but an admin scanning it. `MenusTable` sorts by name through `TranslatedFields::sort()`, `Menu::scopeByName()` orders the option lists that name menus, and `MenuPolicy` has no `reorder()`.
