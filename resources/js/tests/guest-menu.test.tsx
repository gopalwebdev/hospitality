import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vite-plus/test';

import type { AddOnGroup } from '@/lib/add-on-rules';
import Menu, { type MenuItem } from '@/pages/guest/menu';

const tenant = { name: 'Spice Garden', slug: 'spice' };
const homeUrl = 'http://spice.hospitality.test';

const menu = {
    id: 1,
    name: 'Dinner',
    description: null,
    servedFrom: null,
    servedUntil: null,
    isBeingServed: true,
};

/** The common case: 5% GST added at the bill. */
const tax = {
    rateBasisPoints: 500,
    pricesIncludeTax: false,
};

/**
 * An item with only what a test cares about spelled out.
 *
 * Every field the page needs has a default here, so a test about combos does
 * not have to describe a price and a diet mark to get one on screen.
 */
function item(overrides: Partial<MenuItem> = {}): MenuItem {
    return {
        id: 10,
        name: 'Paneer Tikka',
        description: null,
        priceMinorUnits: 24950,
        compareAtPriceMinorUnits: null,
        isServiceRequest: false,
        diet: 'vegetarian',
        addOnGroupIds: [],
        ...overrides,
    };
}

/** A required pick-one, which reads as radios. */
const bread: AddOnGroup = {
    id: 2,
    name: 'Bread',
    minSelections: 1,
    maxSelections: 1,
    options: [
        {
            id: 21,
            name: 'Butter naan',
            priceMinorUnits: 0,
            maxQuantity: 1,
            isPreselected: false,
        },
        {
            id: 22,
            name: 'Garlic naan',
            priceMinorUnits: 2000,
            maxQuantity: 1,
            isPreselected: false,
        },
    ],
};

/** Up to three picks, with cheese allowed twice. */
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
        {
            id: 12,
            name: 'Extra paneer',
            priceMinorUnits: 6000,
            maxQuantity: 1,
            isPreselected: false,
        },
        {
            id: 13,
            name: 'Raita',
            priceMinorUnits: 3000,
            maxQuantity: 1,
            isPreselected: false,
        },
    ],
};

/**
 * Render the page with everything empty but the parts a test names.
 *
 * `order` defaults to what an unarranged menu is served with — the featured
 * rail, the combos rail, then the sections — so only a test about arranging
 * has to spell one out.
 */
function renderMenu(overrides: Partial<Parameters<typeof Menu>[0]> = {}) {
    const sections = overrides.sections ?? [];

    return render(
        <Menu
            tenant={tenant}
            menu={menu}
            featured={[]}
            combos={[]}
            sections={[]}
            order={[
                'featured',
                'combos',
                ...sections.map((section) => section.id),
            ]}
            addOnGroups={[]}
            tax={tax}
            charges={[]}
            acceptingOrders
            quoteUrl={`${homeUrl}/menus/1/basket-quotes`}
            homeUrl={homeUrl}
            {...overrides}
        />,
    );
}

describe('guest menu', () => {
    // The basket is kept on the phone, so one test's would be the next one's.
    beforeEach(() => {
        localStorage.clear();
    });

    it('names the menu and says whether the tenant is taking orders', () => {
        renderMenu();

        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(
            'Dinner',
        );
        expect(screen.getByText('Spice Garden')).toBeInTheDocument();
        expect(screen.getByText('Open')).toBeInTheDocument();
    });

    it('offers a way back to the tiles the guest came in through', () => {
        renderMenu();

        expect(screen.getByRole('link', { name: 'Back' })).toHaveAttribute(
            'href',
            expect.stringContaining('spice.hospitality.test'),
        );
    });

    it('lists each item with its price and diet mark', () => {
        renderMenu({
            sections: [
                {
                    id: 1,
                    name: 'Starters',
                    items: [item({ description: 'Charred in the tandoor.' })],
                    subSections: [],
                },
            ],
        });

        expect(screen.getByText('Starters')).toBeInTheDocument();
        expect(screen.getByText('Paneer Tikka')).toBeInTheDocument();
        // The price arrives as the integer 24950 and is turned into money
        // here, in the guest's own language.
        expect(screen.getByText(/249\.50/)).toBeInTheDocument();
        // The diet mark is a regulatory one, so it carries a label rather
        // than being colour alone.
        expect(screen.getByLabelText('Vegetarian')).toBeInTheDocument();
    });

    it('lists a service request without a diet mark, and names a zero price rather than pricing it', () => {
        renderMenu({
            sections: [
                {
                    id: 1,
                    name: 'Housekeeping',
                    items: [
                        item({
                            id: 20,
                            name: 'Extra Pillow',
                            isServiceRequest: true,
                            diet: null,
                            priceMinorUnits: 0,
                        }),
                    ],
                    subSections: [],
                },
            ],
        });

        expect(screen.getByText('Extra Pillow')).toBeInTheDocument();
        // A pillow has no diet to declare.
        expect(
            screen.queryByLabelText(/vegetarian|egg/i),
        ).not.toBeInTheDocument();
        // "₹0.00" beside a pillow reads as a mistake.
        expect(screen.getByText('Complimentary')).toBeInTheDocument();
        expect(screen.queryByText(/0\.00/)).not.toBeInTheDocument();
    });

    it('offers Add only while the tenant takes orders and the menu is being served', () => {
        const sections = [
            { id: 1, name: 'Starters', items: [item()], subSections: [] },
        ];

        const open = renderMenu({ sections });
        expect(
            screen.getByRole('button', { name: 'Add Paneer Tikka' }),
        ).toBeInTheDocument();
        open.unmount();

        const closed = renderMenu({ sections, acceptingOrders: false });
        expect(
            screen.queryByRole('button', { name: 'Add Paneer Tikka' }),
        ).not.toBeInTheDocument();
        closed.unmount();

        renderMenu({ sections, menu: { ...menu, isBeingServed: false } });
        expect(
            screen.queryByRole('button', { name: 'Add Paneer Tikka' }),
        ).not.toBeInTheDocument();
    });

    it('puts an item with no choices straight in the basket, and the same item again on the same line', () => {
        renderMenu({
            sections: [
                { id: 1, name: 'Starters', items: [item()], subSections: [] },
            ],
        });

        fireEvent.click(
            screen.getByRole('button', { name: 'Add Paneer Tikka' }),
        );
        expect(
            screen.getByRole('button', { name: /1 item/ }),
        ).toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', { name: 'Add Paneer Tikka' }),
        );
        expect(
            screen.getByRole('button', { name: /2 items/ }),
        ).toBeInTheDocument();

        // Kept on the phone for this tenant's menu, so a reload finds it.
        expect(
            JSON.parse(localStorage.getItem('basket:spice:1') ?? '[]'),
        ).toEqual([
            expect.objectContaining({
                type: 'item',
                id: 10,
                choices: [],
                quantity: 2,
            }),
        ]);
    });

    it('customises an item before adding it: Add waits for a required group, and a full group offers nothing more', () => {
        renderMenu({
            sections: [
                {
                    id: 1,
                    name: 'Curries',
                    items: [
                        item({
                            id: 30,
                            name: 'Paneer Butter Masala',
                            priceMinorUnits: 28900,
                            // The item's own order, not the order the menu
                            // sent the groups in.
                            addOnGroupIds: [2, 1],
                        }),
                    ],
                    subSections: [],
                },
            ],
            addOnGroups: [extras, bread],
        });

        expect(screen.getByText('Customisable')).toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', { name: 'Add Paneer Butter Masala' }),
        );

        const sheet = screen.getByRole('dialog');

        expect(
            within(sheet)
                .getAllByRole('heading', { level: 3 })
                .map((heading) => heading.textContent),
        ).toEqual(['Bread', 'Extras']);
        expect(within(sheet).getByText('Choose 1')).toBeInTheDocument();
        expect(within(sheet).getByText('Required')).toBeInTheDocument();
        expect(within(sheet).getByText('Choose up to 3')).toBeInTheDocument();

        // Nothing chosen from Bread yet, so Add says what is missing.
        expect(
            within(sheet).getByRole('button', {
                name: 'Choose 1 more from Bread',
            }),
        ).toBeDisabled();

        fireEvent.click(
            within(sheet).getByRole('radio', { name: 'Garlic naan' }),
        );
        fireEvent.click(
            within(sheet).getByRole('checkbox', { name: 'Extra cheese' }),
        );
        fireEvent.click(
            within(sheet).getByRole('button', {
                name: 'One more Extra cheese',
            }),
        );
        fireEvent.click(
            within(sheet).getByRole('checkbox', { name: 'Extra paneer' }),
        );

        // Two cheese and a paneer are the three picks "up to 3" allows.
        expect(
            within(sheet).getByRole('checkbox', { name: 'Raita' }),
        ).toBeDisabled();

        // ₹289.00, a garlic naan at ₹20.00, two cheese at ₹40.00 and a paneer at ₹60.00.
        fireEvent.click(
            within(sheet).getByRole('button', { name: /Add · ₹?449\.00/ }),
        );

        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            JSON.parse(localStorage.getItem('basket:spice:1') ?? '[]'),
        ).toEqual([
            expect.objectContaining({
                id: 30,
                choices: [
                    { optionId: 22, quantity: 1 },
                    { optionId: 11, quantity: 2 },
                    { optionId: 12, quantity: 1 },
                ],
                quantity: 1,
            }),
        ]);
    });

    it('reads a category, then its subdivisions, each under its own heading', () => {
        renderMenu({
            sections: [
                {
                    id: 1,
                    name: 'Biryani',
                    items: [item({ id: 10, name: 'Plain Biryani' })],
                    subSections: [
                        {
                            id: 5,
                            name: 'Chicken',
                            items: [item({ id: 11, name: 'Chicken Biryani' })],
                        },
                        {
                            id: 6,
                            name: 'Mutton',
                            items: [item({ id: 12, name: 'Mutton Biryani' })],
                        },
                    ],
                },
            ],
        });

        expect(screen.getByText('Biryani')).toBeInTheDocument();
        expect(screen.getByText('Chicken')).toBeInTheDocument();
        expect(screen.getByText('Mutton')).toBeInTheDocument();

        // The item filed straight under the category comes before the
        // subdivisions, which is the order the server sends and the order a
        // guest reads.
        const names = screen
            .getAllByRole('heading', { level: 3 })
            .map((heading) => heading.textContent);

        expect(names).toEqual(['Plain Biryani', 'Chicken', 'Mutton']);

        // An item inside a subdivision is a level deeper than one filed
        // straight under the category, so the nesting survives for anyone
        // navigating by headings.
        expect(
            screen
                .getAllByRole('heading', { level: 4 })
                .map((heading) => heading.textContent),
        ).toEqual(['Chicken Biryani', 'Mutton Biryani']);
    });

    it('reads the blocks in the order the tenant arranged them', () => {
        renderMenu({
            featured: [item({ id: 10, name: 'Paneer Tikka' })],
            combos: [
                {
                    id: 1,
                    name: 'Family Feast',
                    description: null,
                    priceMinorUnits: 99900,
                    compareAtPriceMinorUnits: null,
                    contents: [],
                },
            ],
            sections: [
                { id: 7, name: 'Starters', items: [], subSections: [] },
                { id: 8, name: 'Desserts', items: [], subSections: [] },
            ],
            // The whole point of arranging: a menu that opens with its
            // sections and closes with its combos is the same screen read in
            // a different order.
            order: [7, 'featured', 8, 'combos'],
        });

        expect(
            screen
                .getAllByRole('heading', { level: 2 })
                .map((heading) => heading.textContent?.trim()),
        ).toEqual(['Starters', 'Featured', 'Desserts', 'Combos']);
    });

    it('strikes through the old price beside the one being charged', () => {
        renderMenu({
            sections: [
                {
                    id: 1,
                    name: 'Starters',
                    items: [
                        item({
                            priceMinorUnits: 29900,
                            compareAtPriceMinorUnits: 36000,
                        }),
                    ],
                    subSections: [],
                },
            ],
        });

        expect(screen.getByText(/299\.00/)).toBeInTheDocument();
        expect(screen.getByText(/360\.00/)).toHaveClass('line-through');
    });

    it('shows a combo with what is in it and how many of each', () => {
        renderMenu({
            combos: [
                {
                    id: 3,
                    name: 'Family Feast',
                    description: 'Enough for four.',
                    priceMinorUnits: 99900,
                    compareAtPriceMinorUnits: 120000,
                    contents: [
                        {
                            id: 30,
                            name: 'Chicken Biryani',
                            isServiceRequest: false,
                            diet: 'non-vegetarian',
                            quantity: 2,
                        },
                        {
                            id: 31,
                            name: 'Raita',
                            isServiceRequest: false,
                            diet: 'vegetarian',
                            quantity: 1,
                        },
                    ],
                },
            ],
        });

        expect(screen.getByText('Combos')).toBeInTheDocument();
        expect(screen.getByText('Family Feast')).toBeInTheDocument();
        expect(screen.getByText('You get')).toBeInTheDocument();
        // A quantity of one is left unsaid — "1 × Raita" is noise.
        expect(screen.getByText('2 × Chicken Biryani')).toBeInTheDocument();
        expect(screen.getByText('Raita')).toBeInTheDocument();
        expect(screen.getByText(/1,?200\.00/)).toHaveClass('line-through');
    });

    it('says what the bill adds before a guest orders, one line per charge', () => {
        renderMenu({
            sections: [
                {
                    id: 1,
                    name: 'Starters',
                    items: [item()],
                    subSections: [],
                },
            ],
            charges: [
                {
                    id: 1,
                    name: 'Service Charge',
                    rateBasisPoints: 1000,
                    amountMinorUnits: null,
                },
                {
                    id: 2,
                    name: 'Packing Charge',
                    rateBasisPoints: null,
                    amountMinorUnits: 2000,
                },
            ],
        });

        expect(
            screen.getByText('Prices exclude GST, charged at 5%.'),
        ).toBeInTheDocument();
        // A share of the bill reads as a percentage, a fixed amount as money.
        expect(
            screen.getByText('Service Charge of 10% is added to the bill.'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                /Packing Charge of ₹?20\.00 is added to the bill\./,
            ),
        ).toBeInTheDocument();
    });

    it('mentions no charge a menu does not carry', () => {
        renderMenu({
            sections: [
                { id: 1, name: 'Starters', items: [item()], subSections: [] },
            ],
            tax: { rateBasisPoints: 500, pricesIncludeTax: true },
        });

        expect(
            screen.getByText('Prices include GST at 5%.'),
        ).toBeInTheDocument();
        expect(screen.queryByText(/added to the bill/)).not.toBeInTheDocument();
    });

    it('says when a timed menu is not being served right now', () => {
        renderMenu({
            menu: {
                ...menu,
                name: 'Breakfast',
                // HH:MM, the one shape the server sends whichever driver
                // stored the time.
                servedFrom: '07:00',
                servedUntil: '11:00',
                isBeingServed: false,
            },
        });

        expect(
            screen.getByText(/Served 07:00 to 11:00.*Not being served/),
        ).toBeInTheDocument();
    });

    it('says so plainly when there is no menu yet', () => {
        renderMenu({ acceptingOrders: false });

        expect(screen.getByText(/not ready yet/i)).toBeInTheDocument();
        expect(screen.getByText('Closed')).toBeInTheDocument();
    });
});
