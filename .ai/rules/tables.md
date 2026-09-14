---
paths:
  - 'app/Filament/**/Tables/*.php'
  - 'app/Filament/Tables/**'
  - 'app/Filament/**/RelationManagers/*.php'
---

# Tables

## Never group or sort a Filament table on a translated (JSON) column
Filament's table grouping (`->groups()`/`->defaultGroup()`) orders the query by the group's raw column or relationship attribute unless you override `orderQueryUsing()`. On a translated column that orders whole JSON documents rather than names. While those columns were plain `json` it was worse — Postgres has no ordering operator for `json`, and pages 500'd in production while the SQLite test suite passed. They are `jsonb` now, which sorts without an error and still sorts wrong, so the rule stands; and the suite runs on Postgres, so a page test would catch it.

Group on the parent's integer foreign key instead (e.g. `menu_id`, not `menu.name`), and supply `getTitleFromRecordUsing()` for the header text and `orderQueryUsing()` ordering by the parent's own `position` column via a correlated subquery. See PermissionsTable for the pattern, and MenuItemsTable for what replaced grouping on the menu side. This bit twice in production (menu categories and menu items both 500'd) before being fixed, and the menu's own tables have since stopped grouping altogether — the arrangement is one list built in reading order instead.

## The items page does not group, and does not drag either
Grouping was removed from MenuItemsTable, not fixed, and the reasons are worth keeping because a tree is the obvious thing to reach for.

Two things broke it. Filament turns grouping **off** while reordering (below), so the tree vanished exactly when it was most needed. And a group header only renders when its title differs from the previous row's — so two categories of the same name on different menus, or any ordering that did not keep a branch contiguous, fragmented into the same heading repeated down the page.

What replaced it: filters laid out above the table rather than behind a button — **Menu**, **Category** and **Sub-category**, each narrowed by the one before it, where a Category brings its sub-categories' items with it — and a category column showing the branch (`MenuCategory::path()`, "Biryani › Chicken") so a flat row still says where it sits. Ordering that grouping used to imply is now stated by the query — the menu, oldest first (menus have no position, and a menu's name is a translated column), then the category an item reads under, then a category's own items before its subdivisions', then `position`. It is `orderByRaw` because each rank is a correlated subquery over `menu_categories`, which appears twice: once as the item's own category and once as that category's parent. The page also filters on **Diet**, **Availability**, **Featured** and **On offer**.

Items and service requests are **tabs** rather than a filter (`ListMenuItems::getTabs()`), with deferred badge counts from one grouped query. True-or-false columns read **Yes** / **No** in words under a heading phrased as the question ("Service request?", "Featured?"), not an icon — the project owner found the icons unreadable.

Both filters' option lists come from `MenuSubCategoryForm`, built from one cached read of the tenant's categories plus `MenuCategoryForm::menuOptions()`, which the Menu filter has already loaded. Each list used to eager-load `menu:id,name` for itself, and the second load was the first one's exact query — the duplicate-query guard stopped every page with filters on it.

Every rank is `COALESCE`d rather than left null, so an item filed straight under a category and one inside a subdivision rank against each other by value rather than by where Postgres puts a null.

Nothing links *into* that page with a filter set any more: the menu page's category rows used to, and now add and edit their items in place. If a link comes back, the key is `filters`, not `tableFilters` — `ListRecords` binds the property as `#[Url(as: 'filters')]`, and the wrong key is not an error, it is an unread query parameter and a page showing every item on every menu. Pin such a link with a test that reads the rendered state back.

**Dragging is gone from that page too.** It was offered once the category filter named one category, which was the only way a position could mean anything on a list spanning every menu — but an item's order is now dragged where it is legible, under its own heading on the menu's arrangement screen (`.ai/rules/menus.md`). Two mechanisms for one order was one too many, and the filter-shaped condition went with it.

## A table Filament cannot reorder for you needs its own reorderTable()
Filament reorders by running one UPDATE over `$table->getQuery()`, keyed on the model's **unqualified** `id`. A table outside what that can express overrides `reorderTable()` — keeping the `isReorderable()` check first and unchanged, because that call is the `reorder()` policy method and the only thing keeping the drag away from someone who may only read the menu.

`ArrangeMenu` has no Eloquent query at all: its table is custom data (`->records()`), and a drag there can move a block, a featured item, a combo, a category, a subdivision or an item. It hands the dropped order to `App\Actions\Menus\ApplyMenuArrangement`, which renumbers each list on the menu, then calls `resetTable()` — custom data does not refresh itself after an action. Its actions are covered the same way from one place, `ArrangeMenu::afterActionCalled()`, rather than by each action remembering to.

### The HasManyThrough case
When the table is backed by a `HasManyThrough` that query carries a join, and both the `where in (id, ...)` and the `case when id = ...` it builds become an ambiguous column reference to `id` on Postgres. Dragging a row 500s.

No table here is on a through-relationship now. The featured items tab was — it hung off `Menu::menuItems()` and overrode `reorderTable()` to issue the update against `menu_items` alone, reusing the trait's `protected` `makeTableReorderColumnExpression()` — and it shipped 500ing on every drag, uncaught because the reorder tests only covered plain `hasMany` tables. The featured items are rows of the menu page now. **A reorderable table on a through-relationship, if one comes back, needs that override and a test that calls `reorderTable()` for real.**

Keep the `isReorderable()` check first and unchanged in any such override: it is the `reorder()` policy method, and it is the only thing keeping the drag away from someone who may only read the menu.

## Hand-arranged lists drag, and the position field is never typed
Every ordered list in the panel — the whole of one menu's page (its blocks, featured items, combos, categories and items), home screen rows, the tiles in a row, and charges — uses Filament's own `->reorderable('position')` drag and drop, with its trigger relabelled by `App\Filament\Tables\Reordering::trigger()`: "Rearrange" enters drag mode, "Done" leaves it. The menus list is deliberately not among them: menus are not ordered by hand, and it reads by name (`.ai/rules/menus.md`).

Worth knowing: rows save as they are dropped, not when "Done" is pressed. That button confirms the admin has finished, it does not commit anything — Filament has no deferred-save reorder mode, and building one means overriding `reorderTable()`.

A short-lived pair of move-up/move-down buttons replaced the drag mode and was replaced right back: chevrons have to be clicked once per place moved, and dragging a category from the bottom of a long menu to the top is one gesture. Do not swap back to buttons without saying so.

The `position` TextInput is gone from every form, though: nobody arranges a menu by working out that starters should be 30 and desserts 40. The column still exists and is still what the guest app orders by — it is simply never typed in, and every table needs `->defaultSort('position')`.

`reorderTable()` short-circuits on `isReorderable()`, which is the `reorder()` policy method — so a test that only asserts the button is hidden proves nothing. Call `->call('reorderTable', [...])` and assert the positions did not move.
