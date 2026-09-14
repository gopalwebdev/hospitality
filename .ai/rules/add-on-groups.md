---
paths:
  - 'app/Filament/Tenant/Resources/MenuAddOnGroups/**'
  - 'app/Models/MenuAddOnGroup.php'
  - 'app/Models/MenuAddOnOption.php'
  - 'app/Models/MenuItemAddOnGroup.php'
---

# Add-on groups

## A group is a tenant's, and one group is offered on many items
Customisation is three tables:

- **`menu_add_on_groups`**: a set of choices ("Choose your bread", "Spice level", "Extras") in the tenant's library.
- **`menu_add_on_options`**: each option in a group, with its own price, GST rate, `max_quantity`, pre-selected flag and availability.
- **`menu_item_add_on_groups`**: links a group to an item, with that item's `position`.

Edit "Spice level" once and every item offering it changes. The project owner chose the naming ("Add-on groups → Options") and chose shared groups over a copy per item.

The groups replaced `menu_item_additions`, a flat list of add-ons per item that could not say "pick exactly one bread" or "up to three extras", and had to be retyped on every item.

The shape follows the aggregators it will one day have to speak to:
- **Square:** `min_selected_modifiers` / `max_selected_modifiers`, plus quantities.
- **UrbanPiper:** `min_selectable` / `max_selectable`.
- **Uber Eats:** `quantity_info.min_permitted` / `max_permitted`.

**A variant is a required group of one pick**, with its price as each option's extra charge. "Portion: Half / Full +₹150" needs no variant table.

What a group does not do yet, deliberately:
- **One rule everywhere:** a group has the same rule on every item it is linked to.
- **Combos:** combos are not customised.
- **No nesting:** no option opens a group of its own.

The schema can add each later without a rewrite.

## The rule: at least, at most, and each option's quantity
- **`min_selections`:** 0 makes a group optional; 1 or more makes it required.
- **`max_selections`:** null is no limit.
- **Counting:** picks are counted by quantity, as Square counts them, so two of "Extra cheese" are two picks toward "up to 3". A maximum can therefore exceed the number of options.
- **`max_quantity`:** caps how many of one option a single item takes.

**Stated three times over:**
- **CHECK constraints:**
  - `menu_add_on_groups_min_not_negative`
  - `menu_add_on_groups_max_covers_min`: max null, or at least `GREATEST(min, 1)`
  - `max_quantity BETWEEN 1 AND 99` on options
- **Form validation in `MenuAddOnGroupForm`:**
  - Max choices is not below Min choices (and never below 1)
  - Min choices is not more than the options' quantities add up to
  - no more options are set as the default than Max choices allows — attached to the options repeater itself, so it reads under the table rather than under Max choices
  - an option's Max qty is not more than Max choices — a required group of one pick cannot offer three of an option
  - a group has at least one option
- **Pricing:** the same rules again in `QuoteBasket` when a basket is priced.

"Guest must choose" is a toggle that is not a column: it reads and writes `min_selections`. "At least" is shown only while it is on, but is saved while hidden, because that is when it holds the 0 that makes the group optional. A field saved while hidden is validated while hidden, so its floor follows the toggle (`.ai/rules/filament.md`).

`MenuAddOnGroupForm::ruleSummary()` words a rule for the panel ("Required · Choose 1", "Optional · Up to 3"), and `resources/js/lib/add-on-rules.ts` `ruleOf()` words the same cases for a guest. Change one, change the other.

## Where groups are edited, and where they are linked
`MenuAddOnGroupResource` lists the library under Menu, after Items. Groups are created and edited in 7xl modals, stacked rather than side by side so the options table gets the modal's full width: the group's name and rule in one row on top (Name, Guest must choose, Min choices, Max choices), then its options below, dragged into the order a guest reads them. Prices and rates are typed as money and a percentage, and converted row by row through `PricingFields`.

An option's columns are **Option** (its translated name, `TranslatedFields::textCell()`), **Extra price**, **Max qty**, **GST**, **Default** (ticked when a guest opens the sheet — Medium spice, Regular sugar) and **Available**. Renamed from "Extra charge" / "Up to" / "Pre-selected" / "At least" / "At most" on the project owner's instruction, because the old labels read like the wrong kind of field once the column-shift bug (below) was fixed and their actual contents were visible.

The list reads by name (`TranslatedFields::sort()`) and is not dragged. Each row shows:
- the options under the name
- the rule as a badge
- how many options the group has
- how many items it is used on (grey when none)

Row actions:
- **Attach to items** offers the tenant's items that do not offer the group yet, under "Menu · Category › Sub-category" headings, and adds the group after the groups each item already has.
- **Delete** removes the group's options and links by cascade, and never the items.

Policy is `menu.view` to look and `menu.manage` for everything else.

An item links its groups in its own form: a table repeater on `addOnGroupLinks`, `distinct()` so a group is on an item once (nothing else refuses a second row; there are no unique indexes), with the select's own validation refusing a group the tenant does not have. `MenuAddOnGroupForm::createFromItemForm()` makes a group from that select, which does four things:
- **Authorization:** checks the `create` policy.
- **Tenant:** stamps the tenant itself.
- **Options:** lets the nested repeater save them.
- **Stale options:** flushes `once()`, because the select's options were memoized before the group existed.

**The groups table loads `options` with every column.** The edit form's options repeater fills from the relation the table already loaded, and a column list there filled every price as nothing (`.ai/rules/filament.md`).

**The options table uses `TranslatedFields::textCell()`, never a bare `text()`.** A `->table()` repeater gives a row one cell per top-level component, so a translated name spread as two inputs split into two cells and shifted every column after it — this is what put a price under "GST" and dropped "Available" off the end before it was fixed. See `.ai/rules/filament.md`.

## What a guest is sent, and when an item disappears
`Guest\MenuController` reads the page's links and then its groups with their available options, in two queries whatever the menu's size:
- **Unavailable options:** never sent.
- **Groups with nothing left:** a group with no available option is dropped from every item that offered it.
- **Items whose required group can't be met:** an item is left off the menu, like a sold-out one, when a required group's available options cannot add up to its minimum. `QuoteBasket` calls the same line `unavailable`.

The seeded tenants get realistic groups from `TenantSeeder::ADD_ON_GROUPS`: portion, spice level, bread, extras, dosa sides, sugar, strength, sweet or salted, pillow type and delivery time. They are linked to items by English name, and seeded only for a tenant that has at least one of those items.

Tests:
- `tests/Feature/Tenant/AddOnGroupManagementTest.php`
- `tests/Feature/Tenant/GuestAppTest.php`
- `tests/Feature/Tenant/BasketQuoteTest.php`
- `resources/js/tests/add-on-rules.test.ts`
- `resources/js/tests/guest-menu.test.tsx`
