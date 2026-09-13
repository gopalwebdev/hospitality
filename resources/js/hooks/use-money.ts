import { usePage } from '@inertiajs/react';

import { type CurrencyProp, formatMoney } from '@/lib/money';
import type { TenantSharedProps } from '@/types';

/**
 * Format prices in the tenant's currency and the guest's language.
 *
 * Both halves come from the shared props, so a page hands this an integer and
 * nothing else has to know which currency the tenant prices in.
 */
export function useMoney(): (minorUnits: number) => string {
    const { currency, locale } = usePage<TenantSharedProps>().props;

    const resolved: CurrencyProp = currency ?? {
        code: 'INR',
        minorUnitDigits: 2,
    };

    return (minorUnits: number): string =>
        formatMoney(minorUnits, resolved, locale.current);
}
