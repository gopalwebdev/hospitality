<?php

namespace App\Enums;

use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

/**
 * The categories permissions are shown in.
 *
 * A flat list of a dozen `subject.ability` strings tells a reader nothing about
 * what a role actually does, so every permission belongs to a group and the
 * panel shows them grouped rather than alphabetically.
 *
 * The grouping follows the subject half of a permission name — menu.view is
 * Menu, order.manage is Orders — so a permission added from the panel lands in
 * the right group without anything here changing. Anything with an unrecognised
 * subject falls to Other, which is what makes this safe for custom permissions.
 */
enum PermissionGroup: string
{
    case Menu = 'menu';
    case Orders = 'orders';
    case People = 'people';
    case Tenant = 'tenant';

    /** Reserved for the product team: never granted to a tenant role. */
    case ProductTeam = 'product-team';

    /** Anything added from the panel whose subject is not one of the above. */
    case Other = 'other';

    /**
     * The heading this group is shown under.
     */
    public function label(): string
    {
        return match ($this) {
            self::Menu => 'Menu',
            self::Orders => 'Orders',
            self::People => 'People',
            self::Tenant => 'Tenant',
            self::ProductTeam => 'Product team',
            self::Other => 'Other',
        };
    }

    /**
     * What this group covers, shown under its heading.
     */
    public function description(): string
    {
        return match ($this) {
            self::Menu => 'Seeing and editing what a tenant sells.',
            self::Orders => 'Placing orders and working through them.',
            self::People => 'Managing who staffs a tenant.',
            self::Tenant => 'Changing one tenant\'s own configuration and the home screen guests land on.',
            self::ProductTeam => 'Running the platform itself. A role holding any of these is never offered inside a tenant panel.',
            self::Other => 'Permissions added from this panel. They do nothing until code checks for them.',
        };
    }

    public function icon(): Heroicon
    {
        return match ($this) {
            self::Menu => Heroicon::OutlinedBookOpen,
            self::Orders => Heroicon::OutlinedShoppingBag,
            self::People => Heroicon::OutlinedUsers,
            self::Tenant => Heroicon::OutlinedBuildingStorefront,
            self::ProductTeam => Heroicon::OutlinedShieldCheck,
            self::Other => Heroicon::OutlinedEllipsisHorizontalCircle,
        };
    }

    /**
     * The colour of this group's badge.
     */
    public function color(): string
    {
        return match ($this) {
            self::Menu => 'info',
            self::Orders => 'success',
            self::People => 'warning',
            self::Tenant => 'primary',
            self::ProductTeam => 'danger',
            self::Other => 'gray',
        };
    }

    /**
     * The permission-name subjects this group claims.
     *
     * This is the whole of the mapping: forPermissionName() reads it, and so
     * does the table filter, which has to ask the same question in SQL. Other
     * claims nothing, and takes whatever no other group does.
     *
     * @return list<string>
     */
    public function subjects(): array
    {
        return match ($this) {
            self::Menu => ['menu'],
            self::Orders => ['order', 'payment'],
            self::People => ['user'],
            self::Tenant => ['settings', 'storefront', 'location'],
            self::ProductTeam => ['tenant', 'role', 'permission'],
            self::Other => [],
        };
    }

    /**
     * The group a permission name belongs to.
     *
     * Reads the subject half of `subject.ability`, so this answers for a
     * permission added from the panel as readily as for a declared one.
     */
    public static function forPermissionName(string $name): self
    {
        $subject = Str::before($name, '.');

        foreach (self::cases() as $group) {
            if (in_array($subject, $group->subjects(), strict: true)) {
                return $group;
            }
        }

        return self::Other;
    }

    /**
     * Every subject any group claims.
     *
     * @return list<string>
     */
    public static function everySubject(): array
    {
        return array_merge(...array_map(
            static fn (self $group): array => $group->subjects(),
            self::cases(),
        ));
    }

    /**
     * Every group, in the order the panel shows them.
     *
     * Ordinary tenant work first and the product team's own powers last,
     * which is roughly least to most dangerous.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [
            self::Menu,
            self::Orders,
            self::People,
            self::Tenant,
            self::Other,
            self::ProductTeam,
        ];
    }
}
