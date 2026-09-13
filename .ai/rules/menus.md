---
paths:
  - 'app/Filament/Tenant/Resources/Menus/**'
---

# Menus

## A menu is four tabs, and the first one is the whole menu
`MenuResource::getRecordSubNavigation()` puts four pages across the top of one menu record (`SubNavigationPosition::Top`), in this order: **Arrangement** (`ArrangeMenu`), **Menu** (`EditMenu`: name, description, hours, showing), **Featured dishes** (`ManageMenuFeaturedItems`) and **Combos** (`ManageMenuCombos`).

`getRelations()` is gone and so is `RelationManagers/`. The page used to carry four relation managers — categories, sub-categories, featured, combos — stacked as tabs under the menu's form. Two things were wrong with that and both were the project owner's complaint:

- The dishes, which are what an admin actually looks for, were on a different page entirely, so nothing on the menu's own page showed what was *in* a category.
- Categories and sub-categories were two tables of the same table (`menu_categories`), which read as two unrelated lists rather than one tree.

The two rail tabs stayed as pages rather than relation managers so there is one strip of tabs rather than a strip inside a strip. `ManageRelatedRecords` is a near drop-in for a relation manager: same `$relationship`, same `form()`/`table()`, `$this->getOwnerRecord()`, and `Livewire::test(Page::class, ['record' => $menu->getKey()])` in tests.

## The arrangement is one table of four kinds of row
`MenuArrangementTable` lists, in the order a guest reads them: the **featured rail**, the **combos rail**, every **category**, every **sub-category** under its category, and every **dish** under the heading it is filed on. Indentation is the tree — `nameHtml()` pads by depth — and one drag moves anything.

It is a Filament **custom data** table (`->records()`), because those rows are three models plus two rails that are not rows anywhere. What follows from that:

- Records are plain arrays keyed by `__key` (`ArrayRecord::getKeyName()`), so `$record` in every action and column closure is an `array`. **Nothing in this file may be typed `Model`.** Keys are `featured`, `combos`, `category-<id>`, `item-<id>`, formatted by `ApplyMenuArrangement::categoryKey()` / `::itemKey()` so the table and the action that reads them cannot drift.
- `ArrangeMenu::reorderTable()` overrides Filament's, which writes one UPDATE over an Eloquent query there isn't one of. `reorderable('position', condition: ...)` still matters: it renders the handles and Filament short-circuits the write on the same call, which is the `reorder()` policy check.
- Actions are hand-built (`Action::make()->schema()->fillForm()->action()`), because `CreateAction`/`EditAction`/`DeleteAction` bind to a model. Each write closure loads the model and calls `Gate::authorize()` with it; visibility is answered **once per page** (`$mayManage`) rather than per row per action, which would be a query each.
- A form opened from here is handed the record to ignore (`MenuCategoryForm::configure($schema, $menuId, $editing)`). The schema's own `$record` is whatever surrounds it — for an action modal on a page, that page's **menu** — so the uniqueness rule cannot find the category to exclude on its own. See `.ai/rules/filament.md`.
- **The page redraws from saved rows after every action** (`ArrangeMenu::afterActionCalled()` flushes the cached records). Filament reads all the rows to find the one a row action is about and keeps that copy for the request, so without it a rename left the old name on screen and a deleted category stayed in the list until a reload. Tests pass through `callAction()` either way unless they read the HTML back — the one that pins this does.

Dishes are listed and dragged here but **not edited** here: the dish form carries a repeater bound to a relationship, which needs a real record behind the schema. Every dish row links to the dishes page instead, which is where a dish has always been edited.

## Both rails sit in the same order as the categories
`menus.featured_position` and `menus.combos_position` put the two rails into the number space `menu_categories.position` already uses, so "combos after Starters" is the same kind of drag as moving a category. `App\Enums\MenuBlock` is the pair, and `positionColumn()` is the single place that says which column is which rail.

`Menu::readingOrder($topLevelCategories)` is where the three are merged, and both the panel and `Guest\MenuController` read it — the guest app is *sent* the order (`order`, a list of `'featured' | 'combos' | <section id>`) rather than assembling one, because what a guest reads first is a decision and decisions stay in PHP.

Both columns default to 0, which is where a menu's first category sits too; ties break rails first, then in declaration order. That is what makes a menu nobody has arranged read exactly as it did before the columns existed — featured, combos, then the categories. One drag renumbers the whole top level and nothing ties again.

## Featured dishes are a flag on the dish, ordered on their own tab
`menu_items.is_featured` plus `featured_position` are what a menu leads with; `ManageMenuFeaturedItems` is where they are dragged into order. It hangs off `Menu::menuItems()`, a HasManyThrough that reaches dishes through their categories because a dish carries its category and its tenant, never its menu.

Nothing is created, deleted **or featured** there. The page has no actions at all: it lists the featured dishes and lets them be dragged, which is the one thing the dish's own form cannot do. Whether a dish is featured is the `is_featured` toggle on that form — see `.ai/rules/actions-menus.md`. Where the rail *sits* is a third question again, answered by dragging its row on the arrangement.

`featured_position` is deliberately separate from `position`, which orders a dish inside its category. A dish answers both at once and the two orders are unrelated.

Featuring belongs to one menu, so a dish carried to a category on **another** menu is unfeatured on the way — by `MenuItemObserver` for a single dish and by `MoveCategoryToMenu` for a whole branch. Without that, a dish would appear at the top of a menu nobody had chosen it for, at whatever `featured_position` it happened to hold.

## Combos hang off the menu, and are priced on their own
`menu_combos` sits beside the featured rail rather than under a category: a combo is something a menu leads with, not something in a category, and "which category does a burger meal belong to" is a question with no answer worth having.

Its price is typed, never derived from `menu_combo_items`. The whole point of a combo is that it costs less than the sum of its parts, so a derived price would either be that sum or a discount rule nobody asked for — `MenuCombo::contentsPriceMinorUnits()` exists only to show the saving beside the price, never to set it. Repricing a dish therefore never silently reprices a combo.
