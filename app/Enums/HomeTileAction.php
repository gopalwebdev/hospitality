<?php

namespace App\Enums;

/**
 * What happens when a guest taps a tile on the home screen.
 *
 * Each case names one destination, and each destination needs exactly one
 * target column on home_tiles: Menu needs menu_id, Pdf needs document_path.
 * HomeTile::targetColumn() is the one place that pairing is stated, so a third
 * case is a case here plus a column, and nothing has to be hunted down.
 */
enum HomeTileAction: string
{
    /** Opens one of this tenant's menus. */
    case Menu = 'menu';

    /** Renders an uploaded PDF inside the app, with a back arrow out of it. */
    case Pdf = 'pdf';

    /** Leaves the app for somewhere the tenant is also found. */
    case Link = 'link';

    public function label(): string
    {
        return match ($this) {
            self::Menu => 'Open a menu',
            self::Pdf => 'Show a PDF',
            self::Link => 'Open a link',
        };
    }

    /**
     * What an admin is told this action does when they pick it.
     */
    public function description(): string
    {
        return match ($this) {
            self::Menu => 'Takes the guest to one of your menus.',
            self::Pdf => 'Shows an uploaded PDF — a drinks list, an offer, a licence.',
            self::Link => 'Leaves the app for somewhere else — Instagram, WhatsApp, your own site.',
        };
    }

    /**
     * The column on home_tiles that holds this action's destination.
     *
     * Exactly one target column is filled for any tile, and it is this one.
     * The model's saving guard reads this to refuse a tile that has no
     * destination, or one belonging to a different action.
     */
    public function targetColumn(): string
    {
        return match ($this) {
            self::Menu => 'menu_id',
            self::Pdf => 'document_path',
            self::Link => 'url',
        };
    }

    /**
     * Every target column any action uses.
     *
     * The guard clears the ones that are not this action's, so switching a
     * tile from a PDF to a menu cannot leave an orphaned document behind.
     *
     * @return list<string>
     */
    public static function everyTargetColumn(): array
    {
        return array_values(array_unique(array_map(
            static fn (self $action): string => $action->targetColumn(),
            self::cases(),
        )));
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            static function (array $options, self $action): array {
                $options[$action->value] = $action->label();

                return $options;
            },
            [],
        );
    }
}
