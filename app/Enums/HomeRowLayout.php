<?php

namespace App\Enums;

/**
 * How one row of the guest home screen draws the tiles inside it.
 *
 * The row owns the layout, not the tile: a tenant arranges its home screen
 * by choosing what a band of it looks like, and every tile in that band is
 * drawn the same way. That is why there is no shape column on home_tiles — a
 * circular link sitting in a banner row would be a screen nobody designed.
 *
 * Each case is one shape of band, and the guest app has one renderer per case.
 */
enum HomeRowLayout: string
{
    /** Full-width rectangles, stacked. One tap target per line. */
    case Banner = 'banner';

    /** Image tiles on a horizontal rail the guest swipes through. */
    case Carousel = 'carousel';

    /** Small circles in a strip, for the places a tenant is also found. */
    case Links = 'links';

    public function label(): string
    {
        return match ($this) {
            self::Banner => 'Banner',
            self::Carousel => 'Carousel',
            self::Links => 'Link circles',
        };
    }

    /**
     * What an admin is told this layout does when they pick it.
     */
    public function description(): string
    {
        return match ($this) {
            self::Banner => 'Full-width pictures, one under another. Best for opening a menu.',
            self::Carousel => 'Pictures on a rail the guest swipes sideways. Best for offers and photographs.',
            self::Links => 'Small circles in a row. Best for social links and a phone number.',
        };
    }

    /**
     * The CSS aspect ratio the guest app draws this row's tiles at.
     *
     * Sent to React rather than hardcoded there, so the layout stored is the
     * layout rendered and there is no second list to keep in step.
     */
    public function aspectRatio(): string
    {
        return match ($this) {
            self::Banner => '16 / 9',
            self::Carousel => '4 / 3',
            self::Links => '1 / 1',
        };
    }

    /**
     * Whether this row scrolls sideways rather than wrapping.
     */
    public function isScrollable(): bool
    {
        return match ($this) {
            self::Banner => false,
            self::Carousel, self::Links => true,
        };
    }

    /**
     * Whether a tile in this row is drawn as a circle.
     */
    public function isCircular(): bool
    {
        return $this === self::Links;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            static function (array $options, self $layout): array {
                $options[$layout->value] = $layout->label();

                return $options;
            },
            [],
        );
    }

    /**
     * What each layout does, keyed for a Filament radio's descriptions.
     *
     * @return array<string, string>
     */
    public static function descriptions(): array
    {
        return array_reduce(
            self::cases(),
            static function (array $descriptions, self $layout): array {
                $descriptions[$layout->value] = $layout->description();

                return $descriptions;
            },
            [],
        );
    }
}
