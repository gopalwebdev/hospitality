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
- **`menu_item_add_on_groups`**: links a group to an item, with that item's `position` and, optionally, that item's own `max_selections` — see below.

Edit "Spice level" once and every item offering it changes. The project owner chose the naming ("Add-on groups → Options") and chose shared groups over a copy per item.

The groups replaced `menu_item_additions`, a flat list of add-ons per item that could not say "pick exactly one bread" or "up to three extras", and had to be retyped on every item.

The shape follows the aggregators it will one day have to speak to:
- **Square:** `min_selected_modifiers` / `max_selected_modifiers`, plus quantities.
- **UrbanPiper:** `min_selectable` / `max_selectable`.
- **Uber Eats:** `quantity_info.min_permitted` / `max_permitted`.

Where one of them asks for a minimum, a required group is a minimum of one.

**A variant is a required group of one pick**, with each option's price added to the item's. "Portion: Half / Full +₹150" needs no variant table.

What a group does not do yet, deliberately:
- **Required everywhere:** whether a guest must pick from a group is the same on every item it is linked to. Only the *maximum* may differ per item — see below.
- **Combos:** combos are not customised.
- **No nesting:** no option opens a group of its own.

The schema can add each later without a rewrite.

## Two answers on the group: Required and Maximum picks
The group's own form asks two things, and the columns are named for them:

| Field | Stored as | Means |
| --- | --- | --- |
| Required | `is_required` | a guest picks at least one option before the item goes in |
| Maximum picks | `max_selections` | the most picks; blank is no limit, 1 is one pick |

It used to ask three, with a "Same option more than once" toggle gating a Max qty column that only appeared once it was on. The project owner asked why an option's own limit was hidden behind a group-wide switch at all — a bread group and an extras group should not have to agree on whether *any* option repeats — so the toggle was removed. **Max each (`max_quantity` on the option) is now always a column on every option**, disabled and forced to one while the group is a single pick (Maximum picks = 1), editable otherwise. `MenuAddOnGroupForm::storeOption()` is what forces it.

It used to ask even more before that:
- **Button groups:** Optional / Required and Only one / More than one.
- **Fields after them:** a Minimum, the Maximum and the toggle, after what Toast, Square and DoorDash ask.

The project owner found that confusing and had it cut down, and `min_selections` became `is_required`. **Do not bring a minimum back** without asking.

How a guest sees it:
- **Required with a maximum of one:** radios.
- **Optional with a maximum of one:** a checkbox that moves its tick.

- **Counting:** picks are counted by quantity, as Square counts them, so two of "Extra cheese" are two picks toward "up to 3".
- **`max_quantity`:** caps how many of one option a single item takes, always — there is no group-wide switch gating it any more. `MenuAddOnGroup::quantityAllowedFor()` is the one place that works out the effective cap: an option's own `max_quantity`, never more than the maximum picks in play (the group's own, or an item's own — see below). `picksOffered()` adds the options up with it. `Guest\MenuController` and `QuoteBasket` both go through them.

**Stated three times over:**
- **CHECK constraints:**
  - `menu_add_on_groups_max_in_range`: a group's own max null or 1–99
  - `menu_item_add_on_groups_max_in_range`: an item's own cap on a group null or 1–99
  - `max_quantity BETWEEN 1 AND 99` on options
- **Form validation:**
  - `MenuAddOnGroupForm`: the maximum is 1–99, or blank; no more options are set as the default than the maximum (this rule sits on the options repeater, so its message reads under the table); an option's Max each is not more than the group's own maximum; a group has at least one option.
  - `MenuItemForm`: an item's own cap on a group is 1–99 or blank, and not less than how many of that group's options are ticked as the default — `MenuAddOnGroupForm::defaultsCountOf()`, which reads the same cached lookup the select uses, so this costs no extra query per row. It reads `defaults_count` as an attribute rather than a property, because a `withCount()` aggregate is not a column and the model does not carry one.
- **Pricing:** `QuoteBasket` refuses a line as `invalid` when a required group has nothing picked or a group's picks go over the maximum in play for that item.

`MenuAddOnGroupForm::ruleSummary()` words a rule for the panel's item form ("Required · Choose 1", "Optional · Up to 3", "Required · At least 1") from the *group's own* answers — the select's label does not know an item's own override. `resources/js/lib/add-on-rules.ts` `ruleOf()` words the same cases for a guest, from whatever `maxSelections` the item was actually sent. Change one, change the other.

## An item may cap a group's picks tighter or looser than the group's own
`menu_item_add_on_groups.max_selections` is the one thing that is *not* "one rule everywhere": a "Portion" item might allow only one of an extras group that is "up to 3" everywhere else, or a large thali might allow more. Blank follows the group's own maximum, shown as the field's placeholder so "blank" reads as something rather than nothing. `MenuItemAddOnGroup::effectiveMaxSelections()` is the one place that resolves it (`$this->max_selections ?? $group->max_selections`), and every reader — `QuoteBasket`, `Guest\MenuController` — goes through it or through `MenuAddOnGroup::quantityAllowedFor()`/`picksOffered()`'s own `?? $this->max_selections` fallback, which is the same rule restated on the group side for a caller with no link at hand.

Required stays a property of the group alone: a guest either must pick from "Spice level" wherever it is offered, or need not, and only the *how many* varies per item. The project owner chose this over letting Required vary too, because a group required on one item and optional on another would complicate which items disappear when a required group runs out of options (`canBeMetBy()`), for a case nobody asked for.

`resources/js/lib/add-on-rules.ts` `withItemMaxSelections()` applies an item's own cap to a shared group for the customise sheet: it lowers `maxSelections` and clamps every option's `maxQuantity` to it, so an item capped at one shows the same radio-like "moves the tick" behaviour a single-pick group shows everywhere else, without the group's *other* items losing their own higher cap. The server is what actually enforces it (`QuoteBasket`); the sheet only shapes itself around what it was sent.

## Where groups are edited, and where they are linked
`MenuAddOnGroupResource` lists the library under Menu, after Items. Groups are created and edited in 7xl modals, stacked so the options table gets the full width: the name and the two answers on top, then the options, dragged into the order a guest reads them.

An option's columns:
- **Option:** its translated name, through `TranslatedFields::textCell()`.
- **Price:** `+ ₹`. Blank is free, and a free option is filled back blank so the box reads "Free".
- **Max each:** always a column, disabled and forced to one while the group is a single pick.
- **In stock:** how many are left across every item offering the option; blank is not counted. Written back only when the modal changed it (`.ai/rules/inventory.md`).
- **Default:** ticked for the guest when they open the item (Medium spice, Regular sugar).
- **Available.**

There is no GST column; see below.

Both `->table()` and `->schema()` are fixed arrays now, not closures reading a toggle — there is no longer a state where a row's cell count could disagree with its header count. `TranslatedFields::textCell()` is still what keeps a translated name's two inputs to one cell (`.ai/rules/filament.md`); that trap has nothing to do with the columns being static.

The list reads by name (`TranslatedFields::sort()`) and is not dragged. Each row shows:
- the options under the name
- how many options the group has
- how many items it is used on (grey when none)

It showed the rule as a "Guest picks" badge too, until the project owner had that column removed.

Row actions:
- **Attach to items** offers the tenant's items that do not offer the group yet, under "Menu · Category › Sub-category" headings, and adds the group after the groups each item already has.
- **Delete** removes the group's options and links by cascade, and never the items.

Policy is `menu.view` to look and `menu.manage` for everything else.

An item links its groups in its own form: a table repeater on `addOnGroupLinks`, `distinct()` so a group is on an item once (nothing else refuses a second row; there are no unique indexes), with the select's own validation refusing a group the tenant does not have, and a second column ("Maximum on this item") for the link's own `max_selections`. `MenuAddOnGroupForm::createFromItemForm()` makes a group from that select, which does four things:
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
- **Unavailable options:** never sent — switched off, or counted down to none (`MenuAddOnOption::scopeAvailable()`).
- **Groups with nothing left:** a group with no available option is dropped from every item that offered it.
- **Items whose required group can't be met:** an item is left off the menu, like a sold-out one, when a required group has no available option left, checked against whatever maximum is in play for that item (`picksOffered($maxSelections)`). `QuoteBasket` calls the same line `unavailable`.

A group is sent once, as `{id, name, isRequired, maxSelections, options}` — its own default answers (`.ai/rules/js.md`). Each item names its groups as `addOnGroupLinks: {id, maxSelections}[]`, where `maxSelections` is that item's own cap, null following the group's own; `withItemMaxSelections()` merges the two for the customise sheet.

The seeded tenants get realistic groups from `TenantSeeder::ADD_ON_GROUPS`: portion, spice level, bread, extras, dosa sides, sugar, strength, sweet or salted, pillow type and delivery time. They are linked to items by English name, and seeded only for a tenant that has at least one of those items.

Tests:
- `tests/Feature/Tenant/AddOnGroupManagementTest.php`
- `tests/Feature/Tenant/MenuManagementTest.php` (an item's own cap)
- `tests/Feature/Tenant/GuestAppTest.php`
- `tests/Feature/Tenant/BasketQuoteTest.php`
- `resources/js/tests/add-on-rules.test.ts`
- `resources/js/tests/guest-menu.test.tsx`
- `resources/js/tests/guest-basket.test.tsx`
