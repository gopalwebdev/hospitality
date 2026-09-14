import { describe, expect, it } from 'vite-plus/test';

import {
    type AddOnGroup,
    type AddOnOption,
    canAddOne,
    choicesOf,
    firstShortfall,
    initialPicks,
    isSingleChoice,
    ruleOf,
    step,
    toggle,
    unitPrice,
} from '@/lib/add-on-rules';

function option(overrides: Partial<AddOnOption> & { id: number }): AddOnOption {
    return {
        name: `Option ${String(overrides.id)}`,
        priceMinorUnits: 0,
        maxQuantity: 1,
        isDefault: false,
        ...overrides,
    };
}

const cheese = option({
    id: 11,
    name: 'Extra cheese',
    priceMinorUnits: 4000,
    maxQuantity: 2,
});
const paneer = option({ id: 12, name: 'Extra paneer', priceMinorUnits: 6000 });
const raita = option({ id: 13, name: 'Raita', priceMinorUnits: 3000 });

const extras: AddOnGroup = {
    id: 1,
    name: 'Extras',
    isRequired: false,
    maxSelections: 3,
    options: [cheese, paneer, raita],
};

const butterNaan = option({ id: 21, name: 'Butter naan' });
const garlicNaan = option({
    id: 22,
    name: 'Garlic naan',
    priceMinorUnits: 2000,
    isDefault: true,
});

const bread: AddOnGroup = {
    id: 2,
    name: 'Bread',
    isRequired: true,
    maxSelections: 1,
    options: [butterNaan, garlicNaan],
};

describe('add-on rules', () => {
    it('words each kind of rule the way the panel does', () => {
        expect(ruleOf(bread)).toEqual({
            path: 'customise.choose_exactly',
            replacements: { count: 1 },
        });
        expect(ruleOf(extras)).toEqual({
            path: 'customise.choose_up_to',
            replacements: { count: 3 },
        });
        // Required with a maximum above one is still "up to"; the badge says required.
        expect(ruleOf({ ...extras, isRequired: true })).toEqual({
            path: 'customise.choose_up_to',
            replacements: { count: 3 },
        });
        expect(
            ruleOf({ ...extras, isRequired: true, maxSelections: null }),
        ).toEqual({
            path: 'customise.choose_at_least',
            replacements: { count: 1 },
        });
        expect(ruleOf({ ...extras, maxSelections: null })).toEqual({
            path: 'customise.choose_any',
        });
    });

    it('reads a required pick-one as radios, and an optional one as a checkbox that can be unticked', () => {
        expect(isSingleChoice(bread)).toBe(true);
        expect(isSingleChoice({ ...bread, isRequired: false })).toBe(false);
        expect(isSingleChoice(extras)).toBe(false);
    });

    it('counts two of one option as two picks, and offers nothing more once the group is full', () => {
        let picks = toggle(extras, cheese, {});
        picks = step(extras, cheese, 1, picks);

        expect(picks).toEqual({ 11: 2 });
        // Two is as many cheese as one item takes.
        expect(step(extras, cheese, 1, picks)).toBe(picks);

        picks = toggle(extras, paneer, picks);

        // Two cheese and a paneer are the three "up to 3" allows.
        expect(canAddOne(extras, raita, picks)).toBe(false);
        expect(toggle(extras, raita, picks)).toBe(picks);

        // One fewer cheese makes room again.
        expect(canAddOne(extras, raita, step(extras, cheese, -1, picks))).toBe(
            true,
        );
    });

    it('moves the tick in a group of one pick rather than refusing the second', () => {
        expect(toggle(bread, butterNaan, { 22: 1 })).toEqual({ 21: 1 });
    });

    it('starts with the default options ticked, and says what is still missing', () => {
        expect(initialPicks([bread, extras])).toEqual({ 22: 1 });
        expect(firstShortfall([extras, bread], {})).toEqual({
            group: bread,
            missing: 1,
        });
        expect(firstShortfall([extras, bread], { 21: 1 })).toBeNull();
    });

    it('prices one of the item with its picks, and keeps the picks in the order they are read', () => {
        const picks = { 12: 1, 11: 2, 22: 1 };

        expect(unitPrice(28900, [bread, extras], picks)).toBe(
            28900 + 2000 + 2 * 4000 + 6000,
        );
        expect(choicesOf([bread, extras], picks)).toEqual([
            { optionId: 22, quantity: 1 },
            { optionId: 11, quantity: 2 },
            { optionId: 12, quantity: 1 },
        ]);
    });
});
