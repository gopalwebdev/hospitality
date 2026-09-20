/**
 * Turning stored money into something a guest can read.
 *
 * Prices cross the wire as an integer count of the currency's minor unit —
 * ₹249.50 is 24950 — exactly as the database stores them. Formatting is done
 * here rather than in PHP so the server sends numbers instead of building
 * strings per row, and so the result follows the guest's own language:
 * `Intl.NumberFormat` knows that Indian grouping is 2,49,500 and that a Tamil
 * reader still wants the ₹ symbol.
 */

export type CurrencyProp = {
    /** ISO 4217, e.g. "INR". */
    code: string;
    /** How many digits the minor unit has; 2 for rupees, 0 for yen. */
    fractionDigits: number;
};

/**
 * Building an Intl.NumberFormat is expensive and a menu formats one per row, so
 * each locale-and-currency pair is built once and kept.
 */
const formatters = new Map<string, Intl.NumberFormat>();

function formatterFor(
    locale: string,
    currency: CurrencyProp,
): Intl.NumberFormat {
    const key = `${locale}:${currency.code}:${currency.fractionDigits}`;
    const cached = formatters.get(key);

    if (cached !== undefined) {
        return cached;
    }

    const formatter = new Intl.NumberFormat(locale, {
        style: 'currency',
        currency: currency.code,
        minimumFractionDigits: currency.fractionDigits,
        maximumFractionDigits: currency.fractionDigits,
    });

    formatters.set(key, formatter);

    return formatter;
}

/**
 * Render a stored minor-unit amount as money.
 *
 * The division is the only arithmetic that happens on the client, and it
 * happens once at the point of display — everything before this is exact
 * integers, which is the whole reason money is stored that way.
 */
export function formatMoney(
    minorUnits: number,
    currency: CurrencyProp,
    locale: string,
): string {
    const major = minorUnits / 10 ** currency.fractionDigits;

    return formatterFor(locale, currency).format(major);
}
