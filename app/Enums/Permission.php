<?php

namespace App\Enums;

use Illuminate\Support\Str;

/**
 * Every permission the application recognises.
 *
 * These values are persisted to the `permissions` table by the
 * RolesAndPermissionsSeeder, so treat them as a stable contract:
 * renaming a case requires a data migration.
 */
enum Permission: string
{
    case MenuView = 'menu.view';
    case MenuManage = 'menu.manage';
    case OrderCreate = 'order.create';
    case OrderViewOwn = 'order.view-own';
    case OrderViewAny = 'order.view-any';
    case OrderManage = 'order.manage';

    /** Read what has been paid on an order, by what method, and by whom. */
    case PaymentView = 'payment.view';

    /** Record a payment against one or more orders. */
    case PaymentRecord = 'payment.record';

    /** Reverse a payment already recorded. Kept separate from recording one: reversing money is an owner's call. */
    case PaymentVoid = 'payment.void';

    case UserManage = 'user.manage';

    /** See the tiles a guest lands on. Everyone who reads a menu holds this. */
    case StorefrontView = 'storefront.view';

    /** Arrange the tiles a guest lands on: their order, images and destinations. */
    case StorefrontManage = 'storefront.manage';

    /** Change one tenant's own configuration. */
    case SettingsManage = 'settings.manage';

    /** Manage a tenant's rooms, tables and delivery points. */
    case LocationManage = 'location.manage';

    /** Product-team-level: create, suspend, and delete tenants. */
    case TenantManage = 'tenant.manage';

    /** Product-team-level: define the roles every tenant assigns from. */
    case RoleManage = 'role.manage';

    /** Product-team-level: define the permissions those roles are built from. */
    case PermissionManage = 'permission.manage';

    /**
     * Whether this permission belongs to the product team alone.
     *
     * A product team permission is never granted to a tenant role, and a role
     * holding one is never offered inside a tenant panel. Together those
     * two rules are what stop a tenant owner handing out product team access.
     */
    public function isProductTeamOnly(): bool
    {
        return match ($this) {
            self::TenantManage, self::RoleManage, self::PermissionManage => true,
            default => false,
        };
    }

    /**
     * The category this permission is shown under.
     *
     * Derived from the name rather than listed case by case, so a declared
     * permission and one added from the panel are grouped by the same rule.
     */
    public function group(): PermissionGroup
    {
        return PermissionGroup::forPermissionName($this->value);
    }

    /**
     * Every permission in one group.
     *
     * @return list<self>
     */
    public static function inGroup(PermissionGroup $group): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $permission): bool => $permission->group() === $group,
        ));
    }

    /**
     * A human readable name for this permission.
     */
    public function label(): string
    {
        [$subject, $ability] = explode('.', $this->value);

        return Str::of($ability)->replace('-', ' ')->headline()
            ->append(' ', Str::of($subject)->headline()->toString())
            ->toString();
    }

    /**
     * Every permission reserved for the product team.
     *
     * @return list<self>
     */
    public static function productTeamOnly(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $permission): bool => $permission->isProductTeamOnly(),
        ));
    }

    /**
     * The backing values of every permission reserved for the product team.
     *
     * @return list<string>
     */
    public static function productTeamOnlyValues(): array
    {
        return array_map(
            static fn (self $permission): string => $permission->value,
            self::productTeamOnly(),
        );
    }

    /**
     * The backing values of every permission.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $permission): string => $permission->value,
            self::cases(),
        );
    }
}
