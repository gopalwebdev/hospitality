import { useHttp } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

import type { BasketLine } from '@/hooks/use-basket';

export type QuotedLineStatus = 'ok' | 'unavailable' | 'invalid';

/** One basket line as the server priced it. A line that is not `ok` is priced at nothing. */
export interface QuotedLine {
    key: string;
    status: QuotedLineStatus;
    unitPrice: number;
    total: number;
}

/** What the basket comes to: App\Actions\Menus\QuoteBasket's answer, in minor units throughout. */
export interface Quote {
    lines: QuotedLine[];
    subtotal: number;
    /** Added on top, or the share already inside the prices when `pricesIncludeTax`. */
    tax: number;
    pricesIncludeTax: boolean;
    charges: { id: number; name: string; amount: number }[];
    total: number;
}

type QuoteRequest = {
    lines: Omit<BasketLine, 'name'>[];
};

/**
 * Ask the server what the basket comes to, whenever it is being looked at and changes.
 *
 * The phone's copy is only a claim: a price, the stock or a group's rules may
 * have changed since a line was added. `quote` is the last answer, kept on
 * screen while the next one is fetched.
 */
export function useBasketQuote(
    url: string,
    lines: BasketLine[],
    isLookedAt: boolean,
): { quote: Quote | null; isPricing: boolean } {
    const http = useHttp<QuoteRequest, Quote>({ lines: [] });

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

    return { quote: http.response, isPricing: http.processing };
}
