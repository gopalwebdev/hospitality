---
paths:
  - 'app/Filament/Platform/Resources/Restaurants/**'
---

# Resources Restaurants

## Onboarding a restaurant leads into creating its admin
RestaurantForm reads top to bottom as one sequence — Identity, Limits, Where it trades, How the platform reaches them — in a single column of full-width sections. A two-column grid of sections put the address beside the name, which read as two unrelated starting points.

CreateRestaurant does not return to the list. A restaurant with nobody on its roster cannot be opened by anyone, so it redirects to `UserResource::getUrl('create', ['tenant_id' => ..., 'role' => 'admin'])` and CreateUser's `fillForm()` reads those two query parameters back. The account is deliberately made on the Users page rather than inline here, because that page holds the one-time code confirmation that authorises opening an account at all — creating an admin from the restaurant form would go around it.

UsersRelationManager hangs the roster under the restaurant's own record, read-only: an account is platform-wide, so creating, moving and deleting one belong to the Users resource, and roster membership itself is changed from the restaurant's own panel.
