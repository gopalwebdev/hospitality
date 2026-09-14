<?php

namespace App\Enums;

/**
 * The veg / egg / non-veg mark an item carries.
 *
 * India requires anything served or packaged to be eaten to carry this mark —
 * the green and brown squares — and guests read it before anything else, so
 * every item that is not a service request has one. A service request (an extra
 * pillow, a bedsheet change) never does; MenuItemObserver and the
 * `menu_items_diet_matches_service_request` constraint hold the two together.
 *
 * @see Role for the note on India being the only market for now
 */
enum Diet: string
{
    case Vegetarian = 'vegetarian';
    case Egg = 'egg';
    case NonVegetarian = 'non-vegetarian';

    public function label(): string
    {
        return match ($this) {
            self::Vegetarian => 'Vegetarian',
            self::Egg => 'Contains egg',
            self::NonVegetarian => 'Non-vegetarian',
        };
    }

    /**
     * The colour of the mark shown beside the item, following the Indian
     * convention: green for veg, brown/red for non-veg, amber in between.
     */
    public function color(): string
    {
        return match ($this) {
            self::Vegetarian => 'success',
            self::Egg => 'warning',
            self::NonVegetarian => 'danger',
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
