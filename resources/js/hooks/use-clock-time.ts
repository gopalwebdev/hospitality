import { usePage } from '@inertiajs/react';

import { formatClockTime } from '@/lib/time';
import type { TenantSharedProps } from '@/types';

/**
 * Read a stored `HH:MM` time of day as a 12-hour clock, in the guest's language.
 *
 * The locale comes from the shared props, so a page hands this the string the
 * server sent and nothing else has to know how a time is worded. Beside
 * `useMoney()`, and for the same reasons.
 */
export function useClockTime(): (clock: string) => string {
    const { locale } = usePage<TenantSharedProps>().props;

    return (clock: string): string => formatClockTime(clock, locale.current);
}
