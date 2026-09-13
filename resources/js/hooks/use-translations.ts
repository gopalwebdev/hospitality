import { usePage } from '@inertiajs/react';

import type { LocaleProp, Translations } from '@/types';

export type Replacements = Record<string, string | number>;

export type Translator = {
    /** Look a string up by its dotted path, e.g. `menu.empty`. */
    readonly t: (path: string, replacements?: Replacements) => string;
    readonly locale: LocaleProp;
};

/**
 * Walk a dotted path into the translation tree.
 *
 * Returns null rather than throwing. A missing string should leave one label
 * looking wrong, not take down the screen a guest is holding.
 */
function lookup(translations: Translations, path: string): string | null {
    const value = path
        .split('.')
        .reduce<string | Translations | undefined>(
            (branch, key) =>
                typeof branch === 'object' && branch !== null
                    ? branch[key]
                    : undefined,
            translations,
        );

    return typeof value === 'string' ? value : null;
}

/**
 * Fill Laravel's `:name` placeholders.
 *
 * The strings arrive as they were written in lang/, so the substitution that
 * would normally happen in PHP happens here instead — one small function
 * rather than a second copy of every string with the numbers baked in.
 */
function interpolate(line: string, replacements: Replacements): string {
    return Object.entries(replacements).reduce(
        (filled, [key, value]) => filled.replaceAll(`:${key}`, String(value)),
        line,
    );
}

/**
 * The chrome of the app in the language being served.
 *
 * The strings come from lang/en/guest.php as one shared Inertia prop, so
 * a component asks for them rather than being passed them down. The dish names
 * and section headings are not here — those are the tenant's own words and
 * arrive on the page's own props, already in the right language.
 */
export function useTranslations(): Translator {
    const { translations, locale } = usePage<{
        translations: Translations;
        locale: LocaleProp;
    }>().props;

    const t = (path: string, replacements?: Replacements): string => {
        const line = lookup(translations ?? {}, path) ?? path;

        return replacements === undefined
            ? line
            : interpolate(line, replacements);
    };

    return { t, locale } as const;
}
