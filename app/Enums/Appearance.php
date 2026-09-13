<?php

namespace App\Enums;

/**
 * How the guest app is shaded on the phone reading it.
 *
 * Light and dark are the whole of it: there is no brand colour, no per
 * tenant default, and nothing to configure in the tenant panel. The choice
 * belongs to whoever is holding the phone, because a guest in a dark dining
 * room and one on a bright terrace want different answers and neither is the
 * tenant's to make for them.
 */
enum Appearance: string
{
    case Light = 'light';
    case Dark = 'dark';

    /**
     * What a phone that has never been told otherwise is shown.
     */
    public static function default(): self
    {
        return self::Light;
    }

    /**
     * The one the toggle switches to.
     *
     * Worked out here so the single icon button in each app can name its
     * destination without holding a list of its own.
     */
    public function opposite(): self
    {
        return $this === self::Light ? self::Dark : self::Light;
    }

    public function label(): string
    {
        return match ($this) {
            self::Light => 'Light',
            self::Dark => 'Dark',
        };
    }

    /**
     * The appearance for a stored value, falling back rather than throwing.
     *
     * The cookie this reads is visitor-controlled, so an unknown value is an
     * ordinary thing to be handed, not an error.
     */
    public static function fromRequestValue(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::default();
    }
}
