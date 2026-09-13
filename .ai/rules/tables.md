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

What replaced it: a **Menu** filter and a **Category** filter, the second narrowed to the first, and a category column showing the branch (`MenuCategory::path()`, "Biryani › Chicken") so a flat row still says where it sits. Ordering that grouping used to imply is now stated by the query — menu position, then the category an item reads under, then a category's own items before its subdivisions', then `position`. It is `orderByRaw` because each rank is a correlated subquery over `menu_categories`, which appears twice: once as the item's own category and once as that category's parent. The page also filters on **Service request** and **Diet**.

Every rank is `COALESCE`d rather than left null, so an item filed straight under a category and one inside a subdivision rank against each other by value rather than by where Postgres puts a null.

Linking *into* that page with a filter already set — which every category row on the arrangement does — uses `filters`, not `tableFilters`: `ListRecords` binds the property as `#[Url(as: 'filters')]`. The wrong key is not an error, it is an unread query parameter and a page showing every item on every menu, so the link is pinned by a test that reads the rendered state back.

**Dragging is gone from that page too.** It was offered once the category filter named one category, which was the only way a position could mean anything on a list spanning every menu — but an item's order is now dragged where it is legible, under its own heading on the menu's arrangement screen (`.ai/rules/menus.md`). Two mechanisms for one order was one too many, and the filter-shaped condition went with it.

## A table Filament cannot reorder for you needs its own reorderTable()
Filament reorders by running one UPDATE over `$table->getQuery()`, keyed on the model's **unqualified** `id`. Two tables here are outside what that can express, and both override `reorderTable()` — keeping the `isReorderable()` check first and unchanged, because that call is the `reorder()` policy method and the only thing keeping the drag away from someone who may only read the menu.

`ArrangeMenu` has no Eloquent query at all: its table is custom data (`->records()`), and a drag there can move a rail, a category, a subdivision or an item. It hands the dropped order to `App\Actions\Menus\ApplyMenuArrangement`, which renumbers each list on the menu, then calls `resetTable()` — custom data does not refresh itself after an action. Its actions are covered the same way from one place, `ArrangeMenu::afterActionCalled()`, rather than by each action remembering to.

`ManageMenuFeaturedItems` hits the join problem below.

### The HasManyThrough case
When the table is backed by a `HasManyThrough` that query carries a join, and both the `where in (id, ...)` and the `case when id = ...` it builds become an ambiguous column reference to `id` on Postgres. Dragging a row 500s.

`ManageMenuFeaturedItems` hits this, because it hangs off `Menu::menuItems()` — a HasManyThrough that reaches items through their categories. It overrides `reorderTable()` and issues the same update against `menu_items` alone, with the menu named as a plain subquery instead of a join. `makeTableReorderColumnExpression()` is `protected` on the trait, so the expression itself is reused rather than rewritten.

This shipped broken and untested for a while: dragging the featured row 500'd, and nothing caught it because the reorder tests only covered tables backed by a plain `hasMany`. **Any reorderable table on a through-relationship needs this override and a test that calls `reorderTable()` for real.** The sub-categories table needed one too until both levels of category merged into one table and its relationship became a plain `hasMany`; that table is now rows of the arrangement instead.

Keep the `isReorderable()` check first and unchanged in any such override: it is the `reorder()` policy method, and it is the only thing keeping the drag away from someone who may only read the menu.

## Hand-arranged lists drag, and the position field is never typed
Every ordered list in the panel — menus, the whole of one menu's arrangement, home screen rows, the tiles in a row, a menu's featured items, and charges — uses Filament's own `->reorderable('position')` drag and drop, with its trigger relabelled by `App\Filament\Tables\Reordering::trigger()`: "Rearrange" enters drag mode, "Done" leaves it.

Worth knowing: rows save as they are dropped, not when "Done" is pressed. That button confirms the admin has finished, it does not commit anything — Filament has no deferred-save reorder mode, and building one means overriding `reorderTable()`.

A short-lived pair of move-up/move-down buttons replaced the drag mode and was replaced right back: chevrons have to be clicked once per place moved, and dragging a category from the bottom of a long menu to the top is one gesture. Do not swap back to buttons without saying so.

The `position` TextInput is gone from every form, though: nobody arranges a menu by working out that starters should be 30 and desserts 40. The column still exists and is still what the guest app orders by — it is simply never typed in, and every table needs `->defaultSort('position')`.

`reorderTable()` short-circuits on `isReorderable()`, which is the `reorder()` policy method — so a test that only asserts the button is hidden proves nothing. Call `->call('reorderTable', [...])` and assert the positions did not move.
