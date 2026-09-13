<?php

namespace App\Enums;

/**
 * The languages a tenant's own words are stored in.
 *
 * This is the single source of truth for what "a language" means here: the
 * cookie middleware validates against it, the language toggle is built from it,
 * and every translated column stores one key per case. Adding a language is a
 * case and nothing else.
 *
 * What is translated is what a **tenant wrote** — menu, category, item,
 * add-on and tile names, all of them database columns. What this application
 * writes is English and stays English: `lang/en` is the only directory in
 * `lang/`, so switching language changes the menu a guest reads without
 * changing the words around it. A `lang/ta` existed and was deliberately
 * deleted; do not reintroduce a second copy of the chrome.
 *
 * English is the default and the fallback, which is also why it is the locale
 * every uniqueness rule checks — see App\Models\Concerns\HasTranslatedNames.
 */
enum Locale: string
{
    case English = 'en';
    case Tamil = 'ta';

    /**
     * The language the application falls back to.
     *
     * Every translated field is guaranteed to have this one filled in, because
     * the admin forms require it while the others are optional.
     */
    public static function default(): self
    {
        return self::English;
    }

    /**
     * The language's own name, written in that language.
     *
     * A speaker looking for Tamil is looking for "தமிழ்", not for "Tamil" —
     * so this is deliberately not translated.
     */
    public function label(): string
    {
        return match ($this) {
            self::English => 'English',
            self::Tamil => 'தமிழ்',
        };
    }

    /**
     * The short form shown on the toggle in the guest app.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::English => 'EN',
            self::Tamil => 'தமிழ்',
        };
    }

    /**
     * The label an admin form uses when asking for this language's copy.
     */
    public function fieldLabel(string $field): string
    {
        return sprintf('%s (%s)', $field, $this->englishName());
    }

    /**
     * The language's name in English, for the tenant panel.
     *
     * The panels are worked in English by the tenant's own staff, so a
     * field asking for the Tamil name is clearer labelled "Name (Tamil)" than
     * "Name (தமிழ்)".
     */
    public function englishName(): string
    {
        return match ($this) {
            self::English => 'English',
            self::Tamil => 'Tamil',
        };
    }

    /**
     * The language a visitor is switched to when they tap the toggle.
     *
     * There are two languages, so the toggle is a toggle rather than a menu.
     * With a third case this becomes the next one round, which is still the
     * right behaviour for a single button.
     */
    public function next(): self
    {
        $cases = self::cases();
        $position = array_search($this, $cases, strict: true);

        return $cases[((int) $position + 1) % count($cases)];
    }

    /**
     * The locale for a stored value, falling back rather than throwing.
     *
     * A cookie is visitor-controlled, so an unknown value is an ordinary thing
     * to be handed, not an error.
     */
    public static function fromRequestValue(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::default();
    }

    /**
     * The backing values of every language.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $locale): string => $locale->value,
            self::cases(),
        );
    }
}
