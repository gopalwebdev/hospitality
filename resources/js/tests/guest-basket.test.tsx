import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';

import type { BasketLine } from '@/hooks/use-basket';
import type { PricedBasket, TaxParts } from '@/hooks/use-basket-price';
import type { AddOnGroup } from '@/lib/add-on-rules';
import Menu, { type MenuItem } from '@/pages/guest/menu';

/*
 * The priced basket is the server's answer, so these tests stand in for it:
 * what the basket shows is whatever it is handed, and what it asked for is
 * recorded.
 */
const server = vi.hoisted(() => ({
    priced: null as PricedBasket | null,
    asked: [] as { url: string; lines: BasketLine[]; isWanted: boolean }[],
}));

vi.mock('@/hooks/use-basket-price', () => ({
    useBasketPrice: (url: string, lines: BasketLine[], isWanted: boolean) => {
        server.asked.push({ url, lines, isWanted });

        return { priced: server.priced, isPricing: false };
    },
}));

/**
 * GST levied in halves, as the server splits it: half the rate each side, and
 * the amounts it worked out. `treatment` is what the state's half is called.
 */
function halves(
    cgst: number,
    sgst: number,
    rate = 500,
    treatment: TaxParts['treatment'] = 'intra-state',
): TaxParts {
    return {
        treatment,
        cgstRate: Math.floor(rate / 2),
        cgst,
        sgstRate: rate - Math.floor(rate / 2),
        sgst,
        igstRate: 0,
        igst: 0,
    };
}

const priceUrl = 'http://spice.hospitality.test/menus/1/basket-prices';

const extras: AddOnGroup = {
    id: 1,
    name: 'Extras',
    isRequired: false,
    maxPicks: 3,
    options: [
        {
            id: 11,
            name: 'Extra cheese',
            price: 4000,
            maxPerItem: 2,
            isDefault: false,
        },
    ],
};

const bread: AddOnGroup = {
    id: 2,
    name: 'Bread',
    isRequired: true,
    maxPicks: 1,
    options: [
        {
            id: 22,
            name: 'Garlic naan',
            price: 2000,
            maxPerItem: 1,
            isDefault: false,
        },
    ],
};

const masala: MenuItem = {
    id: 30,
    name: 'Paneer Butter Masala',
    description: null,
    price: 28900,
    originalPrice: null,
    kind: 'consumable',
    diet: 'vegetarian',
    addOnGroupLinks: [
        { id: 2, maxPicks: null },
        { id: 1, maxPicks: null },
    ],
    maxPerOrder: null,
};

function keep(lines: BasketLine[]): void {
    localStorage.setItem('basket:spice:1', JSON.stringify(lines));
}

function renderMenu(items: MenuItem[] = [masala]) {
    return render(
        <Menu
            tenant={{ name: 'Spice Garden', slug: 'spice' }}
            menu={{
                id: 1,
                name: 'Dinner',
                description: null,
                servedFrom: null,
                servedUntil: null,
                isBeingServed: true,
            }}
            featured={[]}
            combos={[]}
            sections={[{ id: 1, name: 'Curries', items, subSections: [] }]}
            order={[1]}
            addOnGroups={[extras, bread]}
            tax={{ rate: 500, pricesIncludeTax: false }}
            charges={[]}
            store={{ isOpen: true, opensAt: '09:00', closesAt: '23:00' }}
            priceUrl={priceUrl}
            homeUrl="http://spice.hospitality.test"
        />,
    );
}

/** The amount on the line of the totals a label names. */
function amountFor(
    sheet: HTMLElement,
    label: string,
): string | null | undefined {
    return within(sheet).getByText(label).closest('div')?.querySelector('dd')
        ?.textContent;
}

describe('guest basket', () => {
    beforeEach(() => {
        localStorage.clear();
        server.priced = null;
        server.asked = [];
    });

    it('lists what is kept on the phone in the page language, with the totals the server priced', () => {
        keep([
            {
                key: 'item:30:22x1:11x2',
                type: 'item',
                id: 30,
                name: 'Paneer Butter Masala',
                choices: [
                    { optionId: 22, quantity: 1 },
                    { optionId: 11, quantity: 2 },
                ],
                quantity: 1,
            },
            {
                key: 'item:99',
                type: 'item',
                id: 99,
                name: 'Prawn Koliwada',
                choices: [],
                quantity: 2,
            },
        ]);

        server.priced = {
            lines: [
                {
                    key: 'item:30:22x1:11x2',
                    status: 'ok',
                    unitPrice: 38900,
                    total: 38900,
                    taxableValue: 38900,
                    tax: 1945,
                    taxParts: halves(973, 972),
                },
                {
                    key: 'item:99',
                    status: 'unavailable',
                    unitPrice: 0,
                    total: 0,
                    taxableValue: 0,
                    tax: 0,
                    taxParts: halves(0, 0, 0),
                },
            ],
            subtotal: 38900,
            tax: 2140,
            taxParts: halves(1070, 1070),
            pricesIncludeTax: false,
            charges: [
                {
                    id: 1,
                    name: 'Service Charge',
                    amount: 3890,
                    taxableValue: 3890,
                    tax: 195,
                    taxParts: halves(97, 98),
                },
            ],
            total: 44930,
        };

        renderMenu();

        // Two lines, three things. The bar carries what they come to before
        // the sheet has been opened at all, so a guest knows what the basket
        // is worth without having to look inside it.
        const bar = screen.getByRole('button', { name: /3 items/ });

        expect(bar).toHaveTextContent(/449\.30/);

        fireEvent.click(bar);

        const sheet = screen.getByRole('dialog');

        expect(
            within(sheet).getByText('Garlic naan, 2 × Extra cheese'),
        ).toBeInTheDocument();
        // Gone from the menu since it was added: named as it was, and flagged.
        expect(within(sheet).getByText('Prawn Koliwada')).toBeInTheDocument();
        expect(
            within(sheet).getByText('No longer available'),
        ).toBeInTheDocument();

        // The line carries its own rate and GST, because one basket can hold
        // a 5% item beside an 18% one.
        expect(
            within(sheet).getByText(/GST 5% · ₹?19\.45/),
        ).toBeInTheDocument();

        expect(amountFor(sheet, 'Subtotal')).toMatch(/389\.00/);
        // Levied in halves, and shown that way.
        expect(amountFor(sheet, 'CGST 2.5%')).toMatch(/10\.70/);
        expect(amountFor(sheet, 'SGST 2.5%')).toMatch(/10\.70/);
        expect(amountFor(sheet, 'Service Charge')).toMatch(/38\.90/);
        expect(amountFor(sheet, 'Total')).toMatch(/449\.30/);

        // Priced against this menu as soon as the basket holds anything,
        // without waiting for the sheet: the bar at the foot of the menu
        // shows the same total and reads the same answer.
        expect(server.asked[0]).toEqual(
            expect.objectContaining({ url: priceUrl, isWanted: true }),
        );
    });

    it('steps and removes a line, and says when GST is already inside the prices', () => {
        keep([
            {
                key: 'item:30:22x1',
                type: 'item',
                id: 30,
                name: 'Paneer Butter Masala',
                choices: [{ optionId: 22, quantity: 1 }],
                quantity: 1,
            },
        ]);

        server.priced = {
            lines: [
                {
                    key: 'item:30:22x1',
                    status: 'ok',
                    unitPrice: 30900,
                    total: 30900,
                    taxableValue: 29429,
                    tax: 1471,
                    taxParts: halves(736, 735),
                },
            ],
            subtotal: 30900,
            tax: 1471,
            taxParts: halves(736, 735),
            pricesIncludeTax: true,
            charges: [],
            total: 30900,
        };

        renderMenu();
        fireEvent.click(screen.getByRole('button', { name: /1 item/ }));

        const sheet = screen.getByRole('dialog');

        // Already in the price, so the line says "Incl." and the halves sit
        // under the total as a note rather than being added to it.
        expect(
            within(sheet).getByText(/Incl. GST 5% · ₹?14\.71/),
        ).toBeInTheDocument();
        expect(
            within(sheet).getByText(/Includes GST of ₹?14\.71/),
        ).toBeInTheDocument();
        expect(amountFor(sheet, 'CGST 2.5%')).toMatch(/7\.36/);
        expect(amountFor(sheet, 'Total')).toMatch(/309\.00/);

        fireEvent.click(
            within(sheet).getByRole('button', {
                name: 'One more Paneer Butter Masala',
            }),
        );

        expect(
            JSON.parse(localStorage.getItem('basket:spice:1') ?? '[]'),
        ).toEqual([expect.objectContaining({ quantity: 2 })]);

        fireEvent.click(
            within(sheet).getByRole('button', {
                name: 'Remove Paneer Butter Masala',
            }),
        );

        expect(
            within(sheet).getByText('Nothing in your basket yet.'),
        ).toBeInTheDocument();
        expect(localStorage.getItem('basket:spice:1')).toBeNull();
    });

    it("stops a line at what one order may hold, counting the item's other lines, and says so", () => {
        keep([
            {
                key: 'item:30:22x1',
                type: 'item',
                id: 30,
                name: 'Paneer Butter Masala',
                choices: [{ optionId: 22, quantity: 1 }],
                quantity: 1,
            },
            {
                key: 'item:30:22x1:11x1',
                type: 'item',
                id: 30,
                name: 'Paneer Butter Masala',
                choices: [
                    { optionId: 22, quantity: 1 },
                    { optionId: 11, quantity: 1 },
                ],
                quantity: 1,
            },
        ]);

        renderMenu([{ ...masala, maxPerOrder: 2 }]);
        fireEvent.click(screen.getByRole('button', { name: /2 items/ }));

        const sheet = screen.getByRole('dialog');
        const steppers = within(sheet).getAllByRole('button', {
            name: 'One more Paneer Butter Masala',
        });

        // One on each line is the two one order may hold.
        expect(steppers).toHaveLength(2);
        steppers.forEach((more) => {
            expect(more).toBeDisabled();
        });
        expect(within(sheet).getAllByText('Up to 2 per order')).toHaveLength(2);
    });
});
