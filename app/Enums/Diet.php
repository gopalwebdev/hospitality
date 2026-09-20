<?php

namespace App\Enums;

/**
 * The veg / vegan / egg / non-veg mark an item carries.
 *
 * India requires anything served or packaged to be eaten to carry this mark —
 * the green and brown squares — and guests read it before anything else, so
 * every item that is not a service request has one. A service request (an extra
 * pillow, a bedsheet change) never does; MenuItemObserver and the
 * `menu_items_diet_matches_service_request` constraint hold the two together.
 *
 * Vegan is a mark of its own rather than a kind of vegetarian. It is a stricter
 * claim — no dairy, no honey — and in a country where most vegetarian cooking
 * uses ghee, curd and milk, a guest who keeps it cannot read it off the green
 * square. FSSAI agrees: its 2022 regulations for vegan-labelled products gave
 * vegan a separate mark rather than folding it into the green one.
 *
 * The cases run the two plant marks, then egg, then non-veg, which is the order
 * they are offered in and tabbed by.
 *
 * @see Role for the note on India being the only market for now
 */
enum Diet: string
{
    case Vegetarian = 'vegetarian';
    case Vegan = 'vegan';
    case Egg = 'egg';
    case NonVegetarian = 'non-vegetarian';

    public function label(): string
    {
        return match ($this) {
            self::Vegetarian => 'Vegetarian',
            self::Vegan => 'Vegan',
            self::Egg => 'Contains egg',
            self::NonVegetarian => 'Non-vegetarian',
        };
    }

    /**
     * The colour of the mark shown beside the item, following the Indian
     * convention: green for veg, brown/red for non-veg, amber in between.
     *
     * Vegan is teal rather than a second green: it has to read as plant-based
     * without being mistaken at a glance for the vegetarian mark beside it.
     */
    public function color(): string
    {
        return match ($this) {
            self::Vegetarian => 'success',
            self::Vegan => 'teal',
            self::Egg => 'warning',
            self::NonVegetarian => 'danger',
        };
    }

    /**
     * Whether an item may carry both of these marks at once.
     *
     * Every mark but vegan replaces the others: nothing is vegetarian and
     * non-vegetarian, and nothing contains egg and is vegan. Vegan is the one
     * exception — it sharpens vegetarian rather than contradicting it, which is
     * why most vegetarian items can carry both.
     */
    public function goesWith(self $other): bool
    {
        if ($this === $other) {
            return true;
        }

        return ($this === self::Vegetarian && $other === self::Vegan)
            || ($this === self::Vegan && $other === self::Vegetarian);
    }

    /**
     * The one mark a guest reads, out of every mark an item carries.
     *
     * The strictest wins: an item marked vegetarian and vegan reads as vegan,
     * which already says it is vegetarian, so the menu keeps one square per
     * item. Null where nothing is carried, which is a service request.
     *
     * @param  iterable<self>  $diets
     */
    public static function strictest(iterable $diets): ?self
    {
        $strictest = null;

        foreach ($diets as $diet) {
            if (! $strictest instanceof self || $diet->strictness() < $strictest->strictness()) {
                $strictest = $diet;
            }
        }

        return $strictest;
    }

    /**
     * How strict a claim this mark is, lowest first.
     *
     * A match rather than the order of the cases, so adding one has to say
     * where it sits rather than inheriting a position nobody chose.
     */
    private function strictness(): int
    {
        return match ($this) {
            self::Vegan => 0,
            self::Vegetarian => 1,
            self::Egg => 2,
            self::NonVegetarian => 3,
        };
    }

    /**
     * Every diet, keyed by stored value, for a select field.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            static function (array $options, self $diet): array {
                $options[$diet->value] = $diet->label();

                return $options;
            },
            [],
        );
    }
}
