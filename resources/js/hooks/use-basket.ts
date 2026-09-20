import { useSyncExternalStore } from 'react';

import type { Choice } from '@/lib/add-on-rules';

/** The most of one line a guest may ask for — the cap the pricing endpoint checks too. */
export const MAX_LINE_QUANTITY = 99;

export type BasketLineType = 'item' | 'combo';

/** One line of a basket: an item or a combo, what it was customised with, and how many. */
export interface BasketLine {
    /** Built from the thing and its choices, so the same thing chosen the same way is one line. */
    key: string;
    type: BasketLineType;
    id: number;
    /** Its name when it was added, for a line the menu no longer lists. */
    name: string;
    choices: Choice[];
    quantity: number;
}

export interface Basket {
    lines: BasketLine[];
    /** Everything in it, each line counted by its quantity. */
    count: number;
    add: (line: Omit<BasketLine, 'key'>) => void;
    setQuantity: (key: string, quantity: number) => void;
    remove: (key: string) => void;
    clear: () => void;
}

const EMPTY: BasketLine[] = [];
const listeners = new Set<() => void>();

/** What was last read for each basket, so a snapshot stays the same array until the basket changes. */
const snapshots = new Map<
    string,
    { raw: string | null; lines: BasketLine[] }
>();

/** Where a basket is kept when the browser will not store it: for the visit, and no longer. */
const unstored = new Map<string, string | null>();

/**
 * Where one menu's basket is kept on this phone.
 *
 * Per tenant and per menu, because a basket is priced against one menu: the
 * breakfast card's basket is not the dinner card's.
 */
export function basketStorageKey(tenantSlug: string, menuId: number): string {
    return `basket:${tenantSlug}:${String(menuId)}`;
}

/**
 * A basket kept on the guest's phone, for one menu.
 *
 * Nothing is ordered through it yet: it is a list to show a member of staff,
 * priced by the server (App\Actions\Menus\PriceBasket). It survives a reload and
 * follows another tab of the same app. Read through useSyncExternalStore, like
 * the appearance, so the first render agrees with a page painted without it.
 */
export function useBasket(storageKey: string): Basket {
    const lines = useSyncExternalStore(
        subscribe,
        () => snapshot(storageKey),
        () => EMPTY,
    );

    const change = (update: (current: BasketLine[]) => BasketLine[]): void => {
        save(storageKey, update(snapshot(storageKey)));
    };

    return {
        lines,
        count: lines.reduce((count, line) => count + line.quantity, 0),
        add: (line) => {
            change((current) => {
                const key = lineKey(line);

                return current.some((existing) => existing.key === key)
                    ? current.map((existing) =>
                          existing.key === key
                              ? {
                                    ...existing,
                                    quantity: capped(
                                        existing.quantity + line.quantity,
                                    ),
                                }
                              : existing,
                      )
                    : [
                          ...current,
                          { ...line, key, quantity: capped(line.quantity) },
                      ];
            });
        },
        setQuantity: (key, quantity) => {
            change((current) =>
                quantity < 1
                    ? current.filter((line) => line.key !== key)
                    : current.map((line) =>
                          line.key === key
                              ? { ...line, quantity: capped(quantity) }
                              : line,
                      ),
            );
        },
        remove: (key) => {
            change((current) => current.filter((line) => line.key !== key));
        },
        clear: () => {
            change(() => EMPTY);
        },
    };
}

function lineKey(line: Omit<BasketLine, 'key'>): string {
    return [
        line.type,
        String(line.id),
        ...line.choices.map(
            (choice) => `${String(choice.optionId)}x${String(choice.quantity)}`,
        ),
    ].join(':');
}

function capped(quantity: number): number {
    return Math.min(Math.max(quantity, 1), MAX_LINE_QUANTITY);
}

function subscribe(listener: () => void): () => void {
    listeners.add(listener);
    window.addEventListener('storage', listener);

    return () => {
        listeners.delete(listener);
        window.removeEventListener('storage', listener);
    };
}

function snapshot(storageKey: string): BasketLine[] {
    const raw = readRaw(storageKey);
    const cached = snapshots.get(storageKey);

    if (cached?.raw === raw) {
        return cached.lines;
    }

    const lines = parse(raw);
    snapshots.set(storageKey, { raw, lines });

    return lines;
}

function save(storageKey: string, lines: BasketLine[]): void {
    writeRaw(storageKey, lines.length === 0 ? null : JSON.stringify(lines));
    listeners.forEach((listener) => {
        listener();
    });
}

function readRaw(storageKey: string): string | null {
    if (unstored.has(storageKey)) {
        return unstored.get(storageKey) ?? null;
    }

    try {
        return window.localStorage.getItem(storageKey);
    } catch {
        return null;
    }
}

function writeRaw(storageKey: string, raw: string | null): void {
    try {
        if (raw === null) {
            window.localStorage.removeItem(storageKey);
        } else {
            window.localStorage.setItem(storageKey, raw);
        }
    } catch {
        unstored.set(storageKey, raw);
    }
}

/** What was stored, keeping only lines that are still the right shape. */
function parse(raw: string | null): BasketLine[] {
    if (raw === null) {
        return EMPTY;
    }

    try {
        const parsed: unknown = JSON.parse(raw);

        return Array.isArray(parsed) ? parsed.filter(isLine) : EMPTY;
    } catch {
        return EMPTY;
    }
}

function isChoice(value: unknown): value is Choice {
    return (
        typeof value === 'object' &&
        value !== null &&
        'optionId' in value &&
        Number.isInteger(value.optionId) &&
        'quantity' in value &&
        Number.isInteger(value.quantity)
    );
}

function isLine(value: unknown): value is BasketLine {
    return (
        typeof value === 'object' &&
        value !== null &&
        'key' in value &&
        typeof value.key === 'string' &&
        'type' in value &&
        (value.type === 'item' || value.type === 'combo') &&
        'id' in value &&
        Number.isInteger(value.id) &&
        'name' in value &&
        typeof value.name === 'string' &&
        'choices' in value &&
        Array.isArray(value.choices) &&
        value.choices.every(isChoice) &&
        'quantity' in value &&
        Number.isInteger(value.quantity)
    );
}
