import type { Replacements } from '@/hooks/use-translations';

/** One choice in an add-on group, as the menu page is sent it. */
export interface AddOnOption {
    id: number;
    name: string;
    /** What one of it adds, in the currency's minor unit; 0 is free. */
    priceMinorUnits: number;
    /** How many of this one option a guest may take on one item: 1 unless its group allows quantities. */
    maxQuantity: number;
    /** Ticked for the guest when the sheet opens: "Medium" on a spice level. */
    isDefault: boolean;
}

/**
 * A set of choices an item is customised with: a spice level, a bread, extras.
 *
 * Sent once per menu and named by id on each item that offers it.
 */
export interface AddOnGroup {
    id: number;
    name: string;
    /** 0 makes the group optional; 1 or more makes it required. */
    minSelections: number;
    /** Null is no limit. */
    maxSelections: number | null;
    options: AddOnOption[];
}

/** How many of each option a guest has picked, by option id. Absent is none. */
export type Picks = Readonly<Record<number, number>>;

/** One picked option and how many of it, as a basket stores it and a quote reads it. */
export interface Choice {
    optionId: number;
    quantity: number;
}

/** A line for the translator: a path into lang/en/guest.php and what fills it. */
export interface Phrase {
    path: string;
    replacements?: Replacements;
}

/*
 * What a guest may pick, worked out while they pick it.
 *
 * These only shape the sheet — which boxes can still be ticked, whether Add is
 * ready, what the button says. The server decides again when the basket is
 * priced (App\Actions\Menus\QuoteBasket), so nothing here is trusted.
 *
 * Picks are counted the way the server counts them: each option by its quantity,
 * so two of "Extra cheese" are two picks toward "up to 3".
 */

export function isRequired(group: AddOnGroup): boolean {
    return group.minSelections >= 1;
}

/**
 * The group's rule in words, the same cases as MenuAddOnGroupForm::ruleSummary() in the panel.
 */
export function ruleOf(group: AddOnGroup): Phrase {
    const { minSelections: min, maxSelections: max } = group;

    if (max !== null && max === min) {
        return {
            path: 'customise.choose_exactly',
            replacements: { count: max },
        };
    }

    if (max !== null && min === 0) {
        return { path: 'customise.choose_up_to', replacements: { count: max } };
    }

    if (max !== null) {
        return { path: 'customise.choose_between', replacements: { min, max } };
    }

    if (min >= 1) {
        return {
            path: 'customise.choose_at_least',
            replacements: { count: min },
        };
    }

    return { path: 'customise.choose_any' };
}

/**
 * Whether the group reads as radios: exactly one pick, and nothing to take two of.
 *
 * An optional pick-one stays a checkbox, because a radio cannot be unticked.
 */
export function isSingleChoice(group: AddOnGroup): boolean {
    return (
        group.minSelections === 1 &&
        group.maxSelections === 1 &&
        group.options.every((option) => option.maxQuantity === 1)
    );
}

/** How many picks have been made from one group. */
export function pickedIn(group: AddOnGroup, picks: Picks): number {
    return group.options.reduce(
        (count, option) => count + (picks[option.id] ?? 0),
        0,
    );
}

/** Whether one more pick fits under the group's maximum. */
export function hasRoomIn(group: AddOnGroup, picks: Picks): boolean {
    return (
        group.maxSelections === null ||
        pickedIn(group, picks) < group.maxSelections
    );
}

/** Whether one more of this option may be taken: its own cap, and the group's. */
export function canAddOne(
    group: AddOnGroup,
    option: AddOnOption,
    picks: Picks,
): boolean {
    return (
        (picks[option.id] ?? 0) < option.maxQuantity && hasRoomIn(group, picks)
    );
}

/** Pick this option alone in its group, as a radio does. */
export function choose(
    group: AddOnGroup,
    option: AddOnOption,
    picks: Picks,
): Picks {
    return withQuantity(option.id, 1, withoutGroup(group, picks));
}

/**
 * Tick or untick an option.
 *
 * In a group that allows one pick, ticking another moves the tick rather than
 * refusing it — the guest changed their mind, and should not have to untick first.
 */
export function toggle(
    group: AddOnGroup,
    option: AddOnOption,
    picks: Picks,
): Picks {
    if ((picks[option.id] ?? 0) > 0) {
        return withQuantity(option.id, 0, picks);
    }

    if (group.maxSelections === 1) {
        return choose(group, option, picks);
    }

    return hasRoomIn(group, picks) ? withQuantity(option.id, 1, picks) : picks;
}

/** One more or one fewer of an option, never past either cap. */
export function step(
    group: AddOnGroup,
    option: AddOnOption,
    delta: 1 | -1,
    picks: Picks,
): Picks {
    if (delta === 1 && !canAddOne(group, option, picks)) {
        return picks;
    }

    return withQuantity(option.id, (picks[option.id] ?? 0) + delta, picks);
}

/** What the sheet opens with: every default option, as far as each group's maximum allows. */
export function initialPicks(groups: AddOnGroup[]): Picks {
    let picks: Picks = {};

    for (const group of groups) {
        for (const option of group.options) {
            if (option.isDefault && hasRoomIn(group, picks)) {
                picks = withQuantity(option.id, 1, picks);
            }
        }
    }

    return picks;
}

/** The first group still short of its minimum, and by how many — what Add says instead of adding. */
export function firstShortfall(
    groups: AddOnGroup[],
    picks: Picks,
): { group: AddOnGroup; missing: number } | null {
    for (const group of groups) {
        const missing = group.minSelections - pickedIn(group, picks);

        if (missing > 0) {
            return { group, missing };
        }
    }

    return null;
}

/**
 * One of the item with these picks: its own price and each option's, by quantity.
 *
 * Plain integer addition for the button. The bill, with its tax and charges, is
 * priced by the server.
 */
export function unitPrice(
    basePriceMinorUnits: number,
    groups: AddOnGroup[],
    picks: Picks,
): number {
    return groups.reduce(
        (total, group) =>
            group.options.reduce(
                (sum, option) =>
                    sum + option.priceMinorUnits * (picks[option.id] ?? 0),
                total,
            ),
        basePriceMinorUnits,
    );
}

/** The picks as a basket line keeps them, in the order the options are read. */
export function choicesOf(groups: AddOnGroup[], picks: Picks): Choice[] {
    return groups.flatMap((group) =>
        group.options
            .filter((option) => (picks[option.id] ?? 0) > 0)
            .map((option) => ({
                optionId: option.id,
                quantity: picks[option.id] ?? 0,
            })),
    );
}

function withQuantity(optionId: number, quantity: number, picks: Picks): Picks {
    const kept = Object.entries(picks).filter(
        ([id]) => Number(id) !== optionId,
    );

    return Object.fromEntries(
        quantity > 0 ? [...kept, [String(optionId), quantity]] : kept,
    );
}

function withoutGroup(group: AddOnGroup, picks: Picks): Picks {
    const inGroup = new Set(group.options.map((option) => option.id));

    return Object.fromEntries(
        Object.entries(picks).filter(([id]) => !inGroup.has(Number(id))),
    );
}
