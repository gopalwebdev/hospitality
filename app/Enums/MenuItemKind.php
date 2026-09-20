<?php

namespace App\Enums;

use Filament\Support\Icons\Heroicon;

/**
 * What a menu item is: something to eat or drink, something to take away, or
 * something asked for done.
 *
 * This was a boolean, `is_service_request`, and the database enforced a
 * biconditional on it: `CHECK (is_service_request = (diets IS NULL))`.
 * Everything that was not a service request was forced to carry a diet mark,
 * whether or not that made sense — which is how a bottle of Mineral Water
 * ended up marked vegetarian, a decision nobody made, and how Towel Set,
 * Toiletry Kit and the rest of housekeeping ended up flagged
 * `is_service_request` when a towel is plainly not a service.
 *
 * The line that actually matters is the one an Indian tax invoice draws:
 * goods take an HSN code, services take a SAC code. That is not the same line
 * as "does this carry a diet mark" — a bottle of water and a towel are both
 * goods, but only the water is something a diet mark makes sense on. Three
 * cases line both questions up correctly at once: Consumable is the only kind
 * `requiresDietMark()`, and Consumable and Goods both read `taxCodeLabel()` as
 * HSN while Service reads it as SAC.
 *
 * "Service request" was also hotel vocabulary in a product that serves
 * restaurants and hospital cafeterias too — a Wheelchair Assistance line
 * reads oddly as a "request" beside a plate of something to eat, and Goods
 * needed no such word at all.
 */
enum MenuItemKind: string
{
    case Consumable = 'consumable';
    case Goods = 'goods';
    case Service = 'service';

    public function label(): string
    {
        return match ($this) {
            self::Consumable => 'Consumable',
            self::Goods => 'Goods',
            self::Service => 'Service',
        };
    }

    /**
     * Whether this kind carries a diet mark.
     *
     * The single source of truth for the pairing between kind and diets: the
     * `menu_items_diets_match_kind` CHECK constraint, MenuItemObserver and
     * MenuItemForm all read this rather than each restating "Consumable" on
     * their own, so a case added later cannot leave one of the three
     * disagreeing with the others.
     */
    public function requiresDietMark(): bool
    {
        return $this === self::Consumable;
    }

    /**
     * What the code on a tax invoice is called for this kind.
     *
     * A consumable is priced and taxed as goods, the same as a sealed bottle
     * of water — an invoice does not know the difference between a drink
     * served at the table and one bought sealed — so only Service reads as
     * SAC.
     */
    public function taxCodeLabel(): string
    {
        return match ($this) {
            self::Consumable, self::Goods => 'HSN',
            self::Service => 'SAC',
        };
    }

    /**
     * The icon shown beside this kind in the panel.
     */
    public function icon(): Heroicon
    {
        return match ($this) {
            self::Consumable => Heroicon::OutlinedListBullet,
            self::Goods => Heroicon::OutlinedShoppingBag,
            self::Service => Heroicon::OutlinedBellAlert,
        };
    }

    /**
     * The colour of the badge shown beside it in the panel.
     */
    public function color(): string
    {
        return match ($this) {
            self::Consumable => 'gray',
            self::Goods => 'warning',
            self::Service => 'info',
        };
    }

    /**
     * Every kind, keyed by stored value, for a select field.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            static function (array $options, self $kind): array {
                $options[$kind->value] = $kind->label();

                return $options;
            },
            [],
        );
    }
}
