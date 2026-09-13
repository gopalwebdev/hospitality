<?php

namespace App\Enums;

/**
 * The roles a user may hold inside a tenant.
 *
 * Product team ownership is not here: it is the users.is_admin column, and an
 * admin is granted everything by a Gate::before check rather than by
 * holding a role.
 *
 * Each role owns the definitive list of permissions granted to it, so the
 * seeder stays a thin projection of what is declared here.
 */
enum Role: string
{
    /** Runs one tenant. Every tenant has at least one. */
    case Owner = 'owner';

    /** Works in one tenant: takes orders and works through them. */
    case Staff = 'staff';

    /** The diner. Reads the menu and places their own orders. */
    case Guest = 'guest';

    /**
     * The permissions granted to this role.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            // A tenant owner owns everything inside their own tenant, and
            // nothing at product team level: the roster of tenants, and the
            // roles and permissions every tenant draws from, stay with
            // the product team. Deriving the exclusions from the enum means a
            // new product team permission is withheld here the day it is added.
            self::Owner => self::everyPermissionExcept(...Permission::productTeamOnly()),

            // Staff work the floor: they read the menu and move orders along,
            // but they do not change what is sold or who works here.
            self::Staff => [
                Permission::MenuView,
                Permission::StorefrontView,
                Permission::OrderViewAny,
                Permission::OrderManage,
            ],

            // A guest reads the menu and orders for themselves. view-own
            // rather than view-any is the whole of the difference from staff.
            self::Guest => [
                Permission::MenuView,
                Permission::StorefrontView,
                Permission::OrderCreate,
                Permission::OrderViewOwn,
            ],
        };
    }

    /**
     * Every permission except the ones named.
     *
     * @return list<Permission>
     */
    private static function everyPermissionExcept(Permission ...$excluded): array
    {
        return array_values(array_filter(
            Permission::cases(),
            static fn (Permission $permission): bool => ! in_array($permission, $excluded, strict: true),
        ));
    }

    /**
     * The backing values of the permissions granted to this role.
     *
     * @return list<string>
     */
    public function permissionValues(): array
    {
        return array_map(
            static fn (Permission $permission): string => $permission->value,
            $this->permissions(),
        );
    }

    /**
     * The backing values of every role.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $role): string => $role->value,
            self::cases(),
        );
    }
}
