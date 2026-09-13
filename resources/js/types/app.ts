/**
 * The props HandleGuestAppRequests shares with every page of the guest app.
 *
 * Anything a single page needs is declared on that page instead.
 */

export type LocaleOption = {
    value: string;
    label: string;
    shortLabel: string;
};

export type LocaleProp = {
    /** The language this page was rendered in. */
    current: string;
    /** The one the toggle switches to, decided server-side. */
    next: string;
    available: LocaleOption[];
};

/**
 * A tree of translated strings, as the lang/ file was written.
 *
 * Nested because the PHP file is: `guest.menu.empty` arrives as
 * `{ menu: { empty: '...' } }` and is read with a dotted path.
 */
export type Translations = {
    [key: string]: string | Translations;
};

/**
 * The tenant's currency, as a code and a scale.
 *
 * Never a formatted string: prices cross the wire as integers and are turned
 * into money in the browser, by resources/js/lib/money.ts.
 */
export type CurrencyProp = {
    code: string;
    minorUnitDigits: number;
};

export type TenantSharedProps = {
    tenant: { name: string; slug: string; typeNoun: string } | null;
    locale: LocaleProp;
    currency: CurrencyProp | null;
    translations: Translations;
    /** What the server painted the page with: 'light' or 'dark'. */
    appearance: string;
};
