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
    rate: 500,
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
        price: 24950,
        originalPrice: null,
        kind: 'consumable',
        diet: 'vegetarian',
        addOnGroupLinks: [],
        maxPerOrder: null,
        ...overrides,
    };
}

/** A required pick-one, which reads as radios. */
const bread: AddOnGroup = {
    id: 2,
    name: 'Bread',
    isRequired: true,
    maxPicks: 1,
    options: [
        {
            id: 21,
            name: 'Butter naan',
            price: 0,
            maxPerItem: 1,
            isDefault: false,
        },
        {
            id: 22,
            name: 'Garlic naan',
            price: 2000,
            maxPerItem: 1,
            isDefault: false,
        },
    ],
};

/** Up to three picks, with cheese allowed twice. */
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
        {
            id: 12,
            name: 'Extra paneer',
            price: 6000,
            maxPerItem: 1,
            isDefault: false,
        },
        {
            id: 13,
            name: 'Raita',
            price: 3000,
            maxPerItem: 1,
            isDefault: false,
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
            store={{ isOpen: true, opensAt: '09:00', closesAt: '23:00' }}
            priceUrl={`${homeUrl}/menus/1/basket-prices`}
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

    it('marks a vegan item apart from a vegetarian one', () => {
        renderMenu({
            sections: [
                {
                    id: 1,
                    name: 'Tiffin',
                    items: [
                        item({ id: 11, name: 'Idli Plate', diet: 'vegan' }),
                        item({ id: 12, name: 'Ghee Roast' }),
                    ],
                    subSections: [],
                },
            ],
        });

        // Vegan is its own mark, not the vegetarian one: a guest who keeps it
        // cannot tell ghee from no ghee off a green square.
        expect(screen.getByLabelText('Vegan')).toBeInTheDocument();
        expect(screen.getByLabelText('Vegetarian')).toBeInTheDocument();
    });

    it('marks a service with a bell rather than a diet, and names a zero price rather than pricing it', () => {
        renderMenu({
            sections: [
                {
                    id: 1,
                    name: 'Housekeeping',
                    items: [
                        item({
                            id: 20,
                            name: 'Wheelchair Assistance',
                            kind: 'service',
                            diet: null,
                            price: 0,
                        }),
                    ],
                    subSections: [],
                },
            ],
        });

        expect(screen.getByText('Wheelchair Assistance')).toBeInTheDocument();
        // A service has no diet to declare, and is marked as what it is instead.
        expect(
            screen.getByRole('img', { name: 'Service' }),
        ).toBeInTheDocument();
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

        const closed = renderMenu({
            sections,
            store: { isOpen: false, opensAt: '09:00', closesAt: '23:00' },
        });
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
                            price: 28900,
                            // The item's own order, not the order the menu
                            // sent the groups in.
                            addOnGroupLinks: [
                                { id: 2, maxPicks: null },
                                { id: 1, maxPicks: null },
                            ],
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

    it("caps a group's picks to this item's own maximum, tighter than the group's own", () => {
        renderMenu({
            sections: [
                {
                    id: 1,
                    name: 'Curries',
                    items: [
                        item({
                            id: 31,
                            name: 'Chicken 65',
                            // Extras is "up to 3" in the group's own library;
                            // this item caps it at one.
                            addOnGroupLinks: [{ id: 1, maxPicks: 1 }],
                        }),
                    ],
                    subSections: [],
                },
            ],
            addOnGroups: [extras],
        });

        fireEvent.click(screen.getByRole('button', { name: 'Add Chicken 65' }));

        const sheet = screen.getByRole('dialog');

        expect(within(sheet).getByText('Choose up to 1')).toBeInTheDocument();

        fireEvent.click(
            within(sheet).getByRole('checkbox', { name: 'Extra cheese' }),
        );
        fireEvent.click(
            within(sheet).getByRole('checkbox', { name: 'Extra paneer' }),
        );

        // One pick moves the tick rather than refusing the second, exactly as
        // an optional pick-one already does.
        expect(
            within(sheet).getByRole('checkbox', { name: 'Extra cheese' }),
        ).not.toBeChecked();
        expect(
            within(sheet).getByRole('checkbox', { name: 'Extra paneer' }),
        ).toBeChecked();
    });

    it('stops offering an item once the basket holds as many as one order may', () => {
        renderMenu({
            sections: [
                {
                    id: 1,
                    name: 'Housekeeping',
                    items: [
                        item({
                            id: 20,
                            name: 'Extra Blanket',
                            kind: 'goods',
                            diet: null,
                            price: 0,
                            maxPerOrder: 2,
                        }),
                    ],
                    subSections: [],
                },
            ],
        });

        const addBlanket = screen.getByRole('button', {
            name: 'Add Extra Blanket',
        });

        fireEvent.click(addBlanket);
        fireEvent.click(addBlanket);

        // Greyed where it was rather than gone, which would read as sold out.
        expect(addBlanket).toBeDisabled();
        expect(screen.getByText('Limit reached')).toBeInTheDocument();
        expect(
            JSON.parse(localStorage.getItem('basket:spice:1') ?? '[]'),
        ).toEqual([expect.objectContaining({ id: 20, quantity: 2 })]);
    });

    it('starts a customised item where the basket leaves off, and stops it at what one order may hold', () => {
        localStorage.setItem(
            'basket:spice:1',
            JSON.stringify([
                {
                    key: 'item:30:21x1',
                    type: 'item',
                    id: 30,
                    name: 'Paneer Butter Masala',
                    choices: [{ optionId: 21, quantity: 1 }],
                    quantity: 1,
                },
            ]),
        );

        renderMenu({
            sections: [
                {
                    id: 1,
                    name: 'Curries',
                    items: [
                        item({
                            id: 30,
                            name: 'Paneer Butter Masala',
                            addOnGroupLinks: [{ id: 2, maxPicks: null }],
                            maxPerOrder: 3,
                        }),
                    ],
                    subSections: [],
                },
            ],
            addOnGroups: [bread],
        });

        fireEvent.click(
            screen.getByRole('button', { name: 'Add Paneer Butter Masala' }),
        );

        const sheet = screen.getByRole('dialog');

        expect(
            within(sheet).getByText('Up to 3 per order'),
        ).toBeInTheDocument();

        const more = within(sheet).getByRole('button', {
            name: 'One more Paneer Butter Masala',
        });

        fireEvent.click(more);

        // One already in the basket and two here are the three one order holds.
        expect(more).toBeDisabled();
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
                    price: 99900,
                    originalPrice: null,
                    maxPerOrder: null,
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
                            price: 29900,
                            originalPrice: 36000,
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
                    price: 99900,
                    originalPrice: 120000,
                    maxPerOrder: null,
                    contents: [
                        {
                            id: 30,
                            name: 'Chicken Biryani',
                            kind: 'consumable',
                            diet: 'non-vegetarian',
                            quantity: 2,
                        },
                        {
                            id: 31,
                            name: 'Raita',
                            kind: 'consumable',
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
                    rate: 1000,
                    amount: null,
                },
                {
                    id: 2,
                    name: 'Packing Charge',
                    rate: null,
                    amount: 2000,
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
            tax: { rate: 500, pricesIncludeTax: true },
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
                // stored the time. The browser is what turns it into a clock
                // reading, so what a guest sees is 12-hour.
                servedFrom: '07:00',
                servedUntil: '11:00',
                isBeingServed: false,
            },
        });

        expect(
            screen.getByText(/Served 7:00 AM to 11:00 AM.*Not being served/),
        ).toBeInTheDocument();
    });

    it('reads every time of day on a 12-hour clock', () => {
        renderMenu({
            // 23:00 is the case that matters: a 24-hour reading of a tenant's
            // closing time is the one a guest has to do arithmetic on.
            store: { isOpen: true, opensAt: '09:00', closesAt: '23:00' },
            menu: {
                ...menu,
                servedFrom: '12:00',
                servedUntil: '00:30',
                isBeingServed: true,
            },
        });

        expect(
            screen.getByText(/Open 9:00 AM to 11:00 PM/),
        ).toBeInTheDocument();
        // Noon and midnight are the two the 12-hour clock gets wrong when it
        // is done by hand: 12 PM and 12 AM, never 0 AM.
        expect(
            screen.getByText(/Served 12:00 PM to 12:30 AM/),
        ).toBeInTheDocument();
    });

    it('says so plainly when there is no menu yet', () => {
        renderMenu({
            store: { isOpen: false, opensAt: '09:00', closesAt: '23:00' },
        });

        expect(screen.getByText(/not ready yet/i)).toBeInTheDocument();
        expect(screen.getByText('Closed')).toBeInTheDocument();
    });
});
