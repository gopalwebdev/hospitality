import {
    type BasketLine,
    type BasketLineType,
    MAX_LINE_QUANTITY,
} from '@/hooks/use-basket';

/**
 * How many of one item or combo a single order may hold, counted across every
 * basket line it is on: a feather pillow and a memory foam one are two towards
 * a maximum of two. A null maximum is no limit.
 *
 * The server checks the same (App\Actions\Menus\QuoteBasket). These only keep
 * the steppers and Add buttons from offering what it would refuse.
 */
export interface OrderLimits {
    minQuantity: number;
    maxQuantity: number | null;
}

/** How many of one item or combo the basket holds, across every line it is on. */
export function quantityHeld(
    lines: BasketLine[],
    type: BasketLineType,
    id: number,
): number {
    return lines.reduce(
        (held, line) =>
            line.type === type && line.id === id ? held + line.quantity : held,
        0,
    );
}

/** How many more of it the basket may take, never more than one line holds. */
export function roomFor(limits: OrderLimits, held: number): number {
    const left =
        limits.maxQuantity === null
            ? MAX_LINE_QUANTITY
            : limits.maxQuantity - held;

    return Math.max(0, Math.min(left, MAX_LINE_QUANTITY));
}

/** What a new line starts at: whatever the minimum still asks for, and at least one. */
export function fewestToAdd(limits: OrderLimits, held: number): number {
    return Math.max(1, limits.minQuantity - held);
}

/** Whether the basket holds as many of it as one order may. */
export function isWithinLimits(limits: OrderLimits, held: number): boolean {
    return (
        held >= limits.minQuantity &&
        (limits.maxQuantity === null || held <= limits.maxQuantity)
    );
}

/** How the limits read to a guest, or null for an item with none. */
export function limitsRule(
    limits: OrderLimits,
): { path: string; replacements: Record<string, number> } | null {
    const { minQuantity: min, maxQuantity: max } = limits;

    if (max === null) {
        return min > 1
            ? { path: 'limits.at_least', replacements: { min } }
            : null;
    }

    if (min === max) {
        return { path: 'limits.exactly', replacements: { count: max } };
    }

    return min > 1
        ? { path: 'limits.between', replacements: { min, max } }
        : { path: 'limits.up_to', replacements: { max } };
}
