---
paths:
  - 'app/Actions/Restaurants/**'
---

# Restaurants

## Roles are held per account, not per restaurant
Spatie teams are off, so a user's roles are global to their account. Someone staffing two restaurants has one set of roles, and changing them from one restaurant panel would change what they can do at the other. SetRestaurantUserRoles is the only path a restaurant panel takes to roles: it refuses when the user staffs more than one restaurant, and filters to Role::assignableWithinRestaurant() so a role carrying a product team permission (restaurant.manage, role.manage, permission.manage) can never be assigned from a tenant panel, whatever the form submits. Scoping roles per restaurant means enabling spatie teams; do not work around this rule instead.
