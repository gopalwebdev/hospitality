import { describe, expect, it } from 'vite-plus/test';

import { formatMoney } from '@/lib/money';

const rupees = { code: 'INR', fractionDigits: 2 };
const yen = { code: 'JPY', fractionDigits: 0 };

describe('money', () => {
    it('turns a stored minor-unit integer into money', () => {
        // ₹249.50 is stored as 24950, and the division happens once, here.
        expect(formatMoney(24950, rupees, 'en')).toContain('249.50');
        expect(formatMoney(24950, rupees, 'en')).toContain('₹');
    });

    it('respects a currency with no minor unit', () => {
        // The scale comes from the server, so a zero-decimal currency is not
        // silently divided by 100.
        expect(formatMoney(2500, yen, 'en')).not.toContain('.');
        expect(formatMoney(2500, yen, 'en')).toContain('2,500');
    });

    it('groups digits the way the reader expects', () => {
        // Indian grouping is 2,49,500 rather than 249,500 — which is the whole
        // reason this is done in the browser rather than with number_format().
        expect(formatMoney(24950000, rupees, 'en-IN')).toContain('2,49,500');
    });

    it('renders free as a zero rather than as nothing', () => {
        expect(formatMoney(0, rupees, 'en')).toContain('0.00');
    });
});
