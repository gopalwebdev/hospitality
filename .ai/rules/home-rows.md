---
paths:
  - 'app/Filament/Tenant/Resources/HomeRows/**'
---

# Home Rows

## The home screen is rows in the panel, and tiles live inside a row
HomeRowResource is the storefront navigation item; there is no standalone tiles resource any more (`filament.tenant.resources.home-tiles.*` became `...home-rows.*`). Rows are created in a modal on the list and dragged into order there; editing one opens a page, because TilesRelationManager hangs under the form — shaping a row and filling it are the same sitting.

A tile only means anything inside a row: the row's HomeRowLayout decides how it is drawn, so HomeTileForm has no shape field. The form's destination section shows exactly one of menu_id / document_path / url, chosen by a live radio on `action`, and HomeTileObserver clears the others and refuses a tile with none.

Testing a tile goes through the relation manager, not a page: `Livewire::test(TilesRelationManager::class, ['ownerRecord' => $row, 'pageClass' => EditHomeRow::class])`, and its create button is a *table* header action — `callAction(TestAction::make('create')->table(), [...])`, not `callAction('create', ...)`.
