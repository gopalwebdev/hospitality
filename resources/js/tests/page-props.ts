import type { TenantSharedProps, Translations } from '@/types';

/**
 * The shared Inertia props a page sees, for tests that render one on its own.
 *
 * The guest app reads the tenant, the language and the chrome strings from
 * usePage(), which only exists inside createInertiaApp. setup.ts mocks that to
 * read from here instead.
 *
 * The strings below mirror lang/en/guest.php closely enough for a
 * component test to assert on, but they are not the real ones and are not meant
 * to be: what the apps actually say in each language is pinned by
 * tests/Feature/LocalizationTest.php, against the files themselves.
 */

const DEFAULT_TRANSLATIONS: Translations = {
    home: { title: 'Welcome', empty: 'No home screen yet.' },
    menu: {
        title: 'Menu',
        empty: 'This menu is not ready yet.',
        featured: 'Featured',
        combos: 'Combos',
        combo_contains: 'You get',
        complimentary: 'Complimentary',
        served_between: 'Served :from to :until',
        not_being_served: 'Not being served right now',
        store_hours: 'Open :from to :until',
        store_closed: 'Closed right now',
        store_closed_today: 'Closed today',
        tax_included: 'Prices include GST at :rate.',
        tax_excluded: 'Prices exclude GST, charged at :rate.',
        charge_rate: ':name of :rate is added to the bill.',
        charge_amount: ':name of :amount is added to the bill.',
        add: 'Add',
        add_named: 'Add :name',
        customisable: 'Customisable',
    },
    customise: {
        required: 'Required',
        optional: 'Optional',
        choose_exactly: 'Choose :count',
        choose_up_to: 'Choose up to :count',
        choose_at_least: 'Choose at least :count',
        choose_any: 'Choose any',
        choose_more: 'Choose :count more from :group',
        option_up_to: 'Up to :count',
        add: 'Add · :price',
        quantity: 'Quantity',
        increase: 'One more :name',
        decrease: 'One fewer :name',
    },
    basket: {
        title: 'Your basket',
        view: 'View basket',
        one_item: '1 item',
        items: ':count items',
        empty: 'Nothing in your basket yet.',
        subtotal: 'Subtotal',
        gst: 'GST',
        gst_included: 'Includes GST of :amount',
        line_gst: 'GST :rate · :amount',
        line_gst_included: 'Incl. GST :rate · :amount',
        total: 'Total',
        remove: 'Remove :name',
        unavailable: 'No longer available',
        invalid: 'Choices need changing',
        pricing: 'Working out the total…',
        hint: 'Show this to a member of staff to place your order.',
        clear: 'Clear basket',
    },
    limits: {
        up_to: 'Up to :max per order',
        reached: 'Limit reached',
    },
    document: { open: 'Open in a new tab', unavailable: 'Not available.' },
    status: { open: 'Open', closed: 'Closed' },
    item: { sold_out: 'Sold out', hidden: 'Hidden', free: 'Free' },
    login: { title: 'Sign in' },
    actions: {
        back: 'Back',
        sign_out: 'Sign out',
        switch_to_dark: 'Switch to dark',
        switch_to_light: 'Switch to light',
        switch_language: 'Switch language',
    },
};

const DEFAULTS: TenantSharedProps = {
    tenant: { name: 'Spice Garden', slug: 'spice' },
    locale: {
        current: 'en',
        next: 'ta',
        available: [
            { value: 'en', label: 'English', shortLabel: 'EN' },
            { value: 'ta', label: 'தமிழ்', shortLabel: 'தமிழ்' },
        ],
    },
    currency: { code: 'INR', fractionDigits: 2 },
    translations: DEFAULT_TRANSLATIONS,
    appearance: 'light',
};

let current: Record<string, unknown> = { ...DEFAULTS };

/**
 * Override some of the shared props for one test.
 */
export function stubPageProps(
    overrides: Partial<TenantSharedProps> & Record<string, unknown> = {},
): void {
    current = { ...DEFAULTS, ...overrides };
}

/**
 * What the mocked usePage() hands back. Called by setup.ts, not by tests.
 */
export function readPageProps(): Record<string, unknown> {
    return current;
}

/**
 * Put the defaults back between tests.
 */
export function resetPageProps(): void {
    current = { ...DEFAULTS };
}
