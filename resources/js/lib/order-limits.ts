import {
    type BasketLine,
    type BasketLineType,
    MAX_LINE_QUANTITY,
} from '@/hooks/use-basket';

/**
 * The most of one item or combo a single order may hold, counted across every
 * basket line it is on: a feather pillow and a memory foam one are two towards
 * a maximum of two. Null is no limit.
 *
 * The server checks the same (App\Actions\Baskets\PriceBasket). These only keep
 * the steppers and Add buttons from offering what it would refuse.
 */
export interface OrderLimits {
    maxPerOrder: number | null;
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
        limits.maxPerOrder === null
            ? MAX_LINE_QUANTITY
            : limits.maxPerOrder - held;

    return Math.max(0, Math.min(left, MAX_LINE_QUANTITY));
}

/** Whether the basket holds no more of it than one order may. */
export function isWithinLimits(limits: OrderLimits, held: number): boolean {
    return limits.maxPerOrder === null || held <= limits.maxPerOrder;
}

/** How the limit reads to a guest, or null for an item with none. */
export function limitsRule(
    limits: OrderLimits,
): { path: string; replacements: Record<string, number> } | null {
    return limits.maxPerOrder === null
        ? null
        : { path: 'limits.up_to', replacements: { max: limits.maxPerOrder } };
}
