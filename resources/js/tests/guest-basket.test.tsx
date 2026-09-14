import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';

import type { BasketLine } from '@/hooks/use-basket';
import type { Quote } from '@/hooks/use-basket-quote';
import type { AddOnGroup } from '@/lib/add-on-rules';
import Menu, { type MenuItem } from '@/pages/guest/menu';

/*
 * The quote is the server's answer, so these tests stand in for it: what the
 * basket shows is whatever it is handed, and what it asked for is recorded.
 */
const server = vi.hoisted(() => ({
    quote: null as Quote | null,
    asked: [] as { url: string; lines: BasketLine[]; isLookedAt: boolean }[],
}));

vi.mock('@/hooks/use-basket-quote', () => ({
    useBasketQuote: (url: string, lines: BasketLine[], isLookedAt: boolean) => {
        server.asked.push({ url, lines, isLookedAt });

        return { quote: server.quote, isPricing: false };
    },
}));

const quoteUrl = 'http://spice.hospitality.test/menus/1/basket-quotes';

const extras: AddOnGroup = {
    id: 1,
    name: 'Extras',
    minSelections: 0,
    maxSelections: 3,
    options: [
        {
            id: 11,
            name: 'Extra cheese',
            priceMinorUnits: 4000,
            maxQuantity: 2,
            isPreselected: false,
        },
    ],
};

const bread: AddOnGroup = {
    id: 2,
    name: 'Bread',
    minSelections: 1,
    maxSelections: 1,
    options: [
        {
            id: 22,
            name: 'Garlic naan',
            priceMinorUnits: 2000,
            maxQuantity: 1,
            isPreselected: false,
        },
    ],
};

const masala: MenuItem = {
    id: 30,
    name: 'Paneer Butter Masala',
    description: null,
    priceMinorUnits: 28900,
    compareAtPriceMinorUnits: null,
    isServiceRequest: false,
    diet: 'vegetarian',
    addOnGroupIds: [2, 1],
};

function keep(lines: BasketLine[]): void {
    localStorage.setItem('basket:spice:1', JSON.stringify(lines));
}

function renderMenu() {
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
            sections={[
                { id: 1, name: 'Curries', items: [masala], subSections: [] },
            ]}
            order={[1]}
            addOnGroups={[extras, bread]}
            tax={{ rateBasisPoints: 500, pricesIncludeTax: false }}
            charges={[]}
            acceptingOrders
            quoteUrl={quoteUrl}
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
        server.quote = null;
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

        server.quote = {
            lines: [
                {
                    key: 'item:30:22x1:11x2',
                    status: 'ok',
                    unitPriceMinorUnits: 38900,
                    totalMinorUnits: 38900,
                },
                {
                    key: 'item:99',
                    status: 'unavailable',
                    unitPriceMinorUnits: 0,
                    totalMinorUnits: 0,
                },
            ],
            subtotalMinorUnits: 38900,
            taxMinorUnits: 1945,
            pricesIncludeTax: false,
            charges: [
                { id: 1, name: 'Service Charge', amountMinorUnits: 3890 },
            ],
            totalMinorUnits: 44735,
        };

        renderMenu();

        // Two lines, three things.
        fireEvent.click(screen.getByRole('button', { name: /3 items/ }));

        const sheet = screen.getByRole('dialog');

        expect(
            within(sheet).getByText('Garlic naan, 2 × Extra cheese'),
        ).toBeInTheDocument();
        // Gone from the menu since it was added: named as it was, and flagged.
        expect(within(sheet).getByText('Prawn Koliwada')).toBeInTheDocument();
        expect(
            within(sheet).getByText('No longer available'),
        ).toBeInTheDocument();

        expect(amountFor(sheet, 'Subtotal')).toMatch(/389\.00/);
        expect(amountFor(sheet, 'GST')).toMatch(/19\.45/);
        expect(amountFor(sheet, 'Service Charge')).toMatch(/38\.90/);
        expect(amountFor(sheet, 'Total')).toMatch(/447\.35/);

        // Priced against this menu, and only once the sheet was open.
        expect(server.asked[0]?.isLookedAt).toBe(false);
        expect(server.asked.at(-1)).toEqual(
            expect.objectContaining({ url: quoteUrl, isLookedAt: true }),
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

        server.quote = {
            lines: [
                {
                    key: 'item:30:22x1',
                    status: 'ok',
                    unitPriceMinorUnits: 30900,
                    totalMinorUnits: 30900,
                },
            ],
            subtotalMinorUnits: 30900,
            taxMinorUnits: 1471,
            pricesIncludeTax: true,
            charges: [],
            totalMinorUnits: 30900,
        };

        renderMenu();
        fireEvent.click(screen.getByRole('button', { name: /1 item/ }));

        const sheet = screen.getByRole('dialog');

        // Already in the price, so a note rather than a line added to it.
        expect(within(sheet).queryByText('GST')).not.toBeInTheDocument();
        expect(
            within(sheet).getByText(/Includes GST of ₹?14\.71/),
        ).toBeInTheDocument();

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
});
