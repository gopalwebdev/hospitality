/**
 * Turning a stored rate into the percentage a guest reads.
 *
 * A rate crosses the wire in basis points — 500 is 5%, 1800 is 18% — because
 * that is how it is stored: an integer, so the arithmetic behind a bill stays
 * exact and nothing between the database and a payment provider sees a float.
 * Only the reader wants a percentage, so the conversion happens here, beside
 * the money and time formatting and for the same reason (`.ai/rules/js.md`).
 */

/**
 * Basis points as a percentage: 500 becomes "5%", 250 "2.5%", 1250 "12.5%".
 *
 * Trailing zeros are trimmed, so a whole-number rate does not read "5.00%".
 * `Number()` on a fixed string is what does the trimming; two decimals is as
 * fine as a rate is ever quoted.
 */
export function formatRate(basisPoints: number): string {
    return `${String(Number((basisPoints / 100).toFixed(2)))}%`;
}

/**
 * The whole rate a split was levied at: its halves added back together.
 *
 * Typed structurally rather than against `TaxParts`, so this file stays free
 * of the app's own shapes.
 */
export function wholeRate(parts: {
    cgstRate: number;
    sgstRate: number;
}): number {
    return parts.cgstRate + parts.sgstRate;
}
