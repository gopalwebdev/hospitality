/**
 * Turning a stored wall-clock time into something a guest can read.
 *
 * Opening hours and a menu's service window cross the wire as the `HH:MM` the
 * database keeps — "09:00", "23:00" — because they are wall-clock times in the
 * tenant's own zone and carry no date at all. Formatting happens here rather
 * than in PHP for the same two reasons money does: the server sends the stored
 * value instead of building a string per row, and `Intl.DateTimeFormat`
 * answers in the guest's own language.
 *
 * The clock is always 12-hour. This application reads a time as "9:00 AM" and
 * never as "21:00", on every surface — see `.ai/rules/general.md`.
 */

/**
 * Building an Intl.DateTimeFormat is expensive and a menu may format several,
 * so one is built per locale and kept.
 */
const formatters = new Map<string, Intl.DateTimeFormat>();

function formatterFor(locale: string): Intl.DateTimeFormat {
    const cached = formatters.get(locale);

    if (cached !== undefined) {
        return cached;
    }

    const formatter = new Intl.DateTimeFormat(locale, {
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    });

    formatters.set(locale, formatter);

    return formatter;
}

/**
 * Render a stored `HH:MM` (or `HH:MM:SS`) wall-clock time as a 12-hour one.
 *
 * The date is arbitrary and local: `Intl` formats an instant, while these are
 * times of day with no date behind them, so the two are built in the browser's
 * own zone and read back in it. That cancels out exactly, which is the point —
 * a tenant that opens at 09:00 reads as 9:00 AM on a phone in any zone.
 *
 * Anything that is not a time is handed back untouched rather than rendered as
 * "Invalid Date": the server is what decides these strings, and a guest should
 * never be shown a formatter's error message.
 */
export function formatClockTime(clock: string, locale: string): string {
    const [hours, minutes] = clock.split(':').map(Number);

    if (
        !Number.isInteger(hours) ||
        !Number.isInteger(minutes) ||
        hours < 0 ||
        hours > 23 ||
        minutes < 0 ||
        minutes > 59
    ) {
        return clock;
    }

    return formatterFor(locale).format(new Date(2000, 0, 1, hours, minutes));
}
