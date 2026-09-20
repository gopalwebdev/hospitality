import { useHttp } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

import type { BasketLine } from '@/hooks/use-basket';

export type PricedLineStatus = 'ok' | 'unavailable' | 'invalid';

/** How this bill's GST is levied, which decides what the state's half is called. */
export type GstTreatment = 'intra-state' | 'union-territory' | 'inter-state';

/**
 * One amount's GST, split into the parts a bill shows separately.
 *
 * Rates are basis points and amounts are minor units, both integers, exactly as
 * the server works them out — the split is never re-derived here, because
 * halving a total does not reliably add back up to what was charged.
 * UTGST rides the SGST fields: only the wording differs.
 */
export interface TaxParts {
    treatment: GstTreatment;
    cgstRate: number;
    cgst: number;
    sgstRate: number;
    sgst: number;
    igstRate: number;
    igst: number;
}

/** One basket line as the server priced it. A line that is not `ok` is priced at nothing. */
export interface PricedLine {
    key: string;
    status: PricedLineStatus;
    unitPrice: number;
    total: number;
    /** What the rate was charged on: the line, less any GST already inside it. */
    taxableValue: number;
    /** This line's GST in all — the parts below added up. */
    tax: number;
    taxParts: TaxParts;
}

/** One charge on the bill, priced and taxed with the supply it belongs to. */
export interface PricedCharge {
    id: number;
    name: string;
    amount: number;
    taxableValue: number;
    tax: number;
    taxParts: TaxParts;
}

/** What the basket comes to: App\Actions\Menus\PriceBasket's answer, in minor units throughout. */
export interface PricedBasket {
    lines: PricedLine[];
    subtotal: number;
    /** Added on top, or the share already inside the prices when `pricesIncludeTax`. */
    tax: number;
    /** The same total, split into the parts a bill shows separately. */
    taxParts: TaxParts;
    pricesIncludeTax: boolean;
    charges: PricedCharge[];
    total: number;
}

type PriceRequest = {
    lines: Omit<BasketLine, 'name'>[];
};

/**
 * Ask the server what the basket comes to, whenever it is being looked at and changes.
 *
 * The phone's copy is only a claim: a price, the stock or a group's rules may
 * have changed since a line was added. `priced` is the last answer, kept on
 * screen while the next one is fetched.
 */
export function useBasketPrice(
    url: string,
    lines: BasketLine[],
    isLookedAt: boolean,
): { priced: PricedBasket | null; isPricing: boolean } {
    const http = useHttp<PriceRequest, PricedBasket>({ lines: [] });

    // Inertia hands back new request helpers on every render, so the request
    // below reads the latest ones rather than re-running each time they change.
    const latest = useRef(http);

    useEffect(() => {
        latest.current = http;
    });

    useEffect(() => {
        if (!isLookedAt || lines.length === 0) {
            return;
        }

        latest.current.transform(() => ({
            lines: lines.map(({ key, type, id, choices, quantity }) => ({
                key,
                type,
                id,
                choices,
                quantity,
            })),
        }));

        // A failed request leaves the last answer on screen.
        latest.current.post(url).catch(() => null);
    }, [isLookedAt, lines, url]);

    return { priced: http.response, isPricing: http.processing };
}
