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
- **`menu_add_on_options`**: each option in a group, with its own price, `max_quantity`, default flag (`is_default`) and availability, and no tax rate of its own.
- **`menu_item_add_on_groups`**: links a group to an item, with that item's `position`.

Edit "Spice level" once and every item offering it changes. The project owner chose the naming ("Add-on groups → Options") and chose shared groups over a copy per item.

The groups replaced `menu_item_additions`, a flat list of add-ons per item that could not say "pick exactly one bread" or "up to three extras", and had to be retyped on every item.

The shape follows the aggregators it will one day have to speak to:
- **Square:** `min_selected_modifiers` / `max_selected_modifiers`, plus quantities.
- **UrbanPiper:** `min_selectable` / `max_selectable`.
- **Uber Eats:** `quantity_info.min_permitted` / `max_permitted`.

Where one of them asks for a minimum, a required group is a minimum of one.

**A variant is a required group of one pick**, with each option's price added to the item's. "Portion: Half / Full +₹150" needs no variant table.

What a group does not do yet, deliberately:
- **One rule everywhere:** a group has the same rule on every item it is linked to.
- **Combos:** combos are not customised.
- **No nesting:** no option opens a group of its own.

The schema can add each later without a rewrite.

## Three answers: Required, Maximum, and the same option twice
The form asks three things, and the columns are named for them:

| Field | Stored as | Means |
| --- | --- | --- |
| Required | `is_required` | a guest picks at least one option before the item goes in |
| Maximum | `max_selections` | the most picks; blank is no limit, 1 is one pick |
| Same option more than once | `allows_quantities` | a guest may take one option twice ("Extra cheese × 2"); hidden while Maximum is 1 |

It used to ask more:
- **Button groups:** Optional / Required and Only one / More than one.
- **Fields after them:** a Minimum, the Maximum and the toggle, after what Toast, Square and DoorDash ask.

The project owner found that confusing and had it cut to these three, and `min_selections` became `is_required`. **Do not bring a minimum back** without asking.

How a guest sees it:
- **Required with a maximum of one:** radios.
- **Optional with a maximum of one:** a checkbox that moves its tick.

- **Counting:** picks are counted by quantity, as Square counts them, so two of "Extra cheese" are two picks toward "up to 3".
- **`max_quantity`:** caps how many of one option a single item takes. It is only read while the group `allows_quantities`. `MenuAddOnGroup::quantityAllowedFor()` is the one place that says so (otherwise it is one), and `picksOffered()` adds the options up with it. `Guest\MenuController` and `QuoteBasket` both go through them.

**Stated three times over:**
- **CHECK constraints:**
  - `menu_add_on_groups_max_in_range`: max null or 1–99
  - `menu_add_on_groups_quantities_need_more_than_one`: a group that allows quantities has no maximum, or one of at least two
  - `max_quantity BETWEEN 1 AND 99` on options
- **Form validation in `MenuAddOnGroupForm`:**
  - The maximum is 1–99, or blank.
  - No more options are set as the default than the maximum. This rule sits on the options repeater, so its message reads under the table.
  - An option's Max qty is not more than the maximum.
  - A group has at least one option.
  - Same option more than once is saved off while the maximum is 1, whatever its box was left at: what it saves reads the Maximum.
- **Pricing:** `QuoteBasket` refuses a line as `invalid` when a required group has nothing picked or a group's picks go over its maximum.

`MenuAddOnGroupForm::ruleSummary()` words a rule for the panel's item form ("Required · Choose 1", "Optional · Up to 3", "Required · At least 1"). `resources/js/lib/add-on-rules.ts` `ruleOf()` words the same cases for a guest. Change one, change the other.

## Where groups are edited, and where they are linked
`MenuAddOnGroupResource` lists the library under Menu, after Items. Groups are created and edited in 7xl modals, stacked so the options table gets the full width: the name and the three answers on top, then the options, dragged into the order a guest reads them.

An option's columns:
- **Option:** its translated name, through `TranslatedFields::textCell()`.
- **Price:** `+ ₹`. Blank is free, and a free option is filled back blank so the box reads "Free".
- **Max qty:** only while the group allows quantities.
- **Default:** ticked for the guest when they open the item (Medium spice, Regular sugar).
- **Available.**

There is no GST column; see below.

**Max qty is a column only while "Same option more than once" is on.** Both `->table()` and `->schema()` are closures reading that answer, so a row always has one cell per column. While it is off, the save writes a Max qty of one. `AddOnGroupManagementTest` switches it on in an open modal and compares the header cells with the row cells in the rendered HTML.

The list reads by name (`TranslatedFields::sort()`) and is not dragged. Each row shows:
- the options under the name
- how many options the group has
- how many items it is used on (grey when none)

It showed the rule as a "Guest picks" badge too, until the project owner had that column removed.

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

**The options table uses `TranslatedFields::textCell()`, never a bare `text()`.** A `->table()` repeater gives a row one cell per top-level component, so a translated name spread as two inputs split into two cells and shifted every column after it. That put a price under the since-removed GST column and dropped "Available" off the end before it was fixed. See `.ai/rules/filament.md`.

## An add-on is taxed at its item's rate
An option has no `tax_rate_basis_points`. Section 8(a) of the CGST Act taxes a composite supply at the rate of its principal supply, and extra cheese on a paneer tikka is part of the paneer tikka. So `QuoteBasket` taxes every part of an item's line at the item's rate.

This replaced two things:
- **The form:** a GST column on the options table, which was the form's most confusing field.
- **`QuoteBasket`:** it taxed an option at its own rate or the tenant's, never the item's, which was wrong in law.

## What a guest is sent, and when an item disappears
`Guest\MenuController` reads the page's links and then its groups with their available options, in two queries whatever the menu's size:
- **Unavailable options:** never sent.
- **Groups with nothing left:** a group with no available option is dropped from every item that offered it.
- **Items whose required group can't be met:** an item is left off the menu, like a sold-out one, when a required group has no available option left. `QuoteBasket` calls the same line `unavailable`.

A group arrives as `{id, name, isRequired, maxSelections, options}` (`.ai/rules/js.md`).

The seeded tenants get realistic groups from `TenantSeeder::ADD_ON_GROUPS`: portion, spice level, bread, extras, dosa sides, sugar, strength, sweet or salted, pillow type and delivery time. They are linked to items by English name, and seeded only for a tenant that has at least one of those items.

Tests:
- `tests/Feature/Tenant/AddOnGroupManagementTest.php`
- `tests/Feature/Tenant/GuestAppTest.php`
- `tests/Feature/Tenant/BasketQuoteTest.php`
- `resources/js/tests/add-on-rules.test.ts`
- `resources/js/tests/guest-menu.test.tsx`
