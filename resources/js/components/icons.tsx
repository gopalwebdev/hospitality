import type { SVGProps } from 'react';

/**
 * The handful of icons the guest app needs.
 *
 * Written out rather than pulled from an icon package: a handful of glyphs do
 * not earn a dependency, and these ship as part of the bundle instead of as
 * another download on a phone at a table.
 *
 * All of them inherit `currentColor` and size themselves from the button they
 * sit in, which is what `[&_svg]:size-4` in components/ui/button.tsx expects.
 */

type IconProps = SVGProps<SVGSVGElement>;

const base = {
    viewBox: '0 0 24 24',
    fill: 'none',
    stroke: 'currentColor',
    strokeWidth: 1.75,
    strokeLinecap: 'round',
    strokeLinejoin: 'round',
    'aria-hidden': true,
    focusable: false,
} as const;

/** Shown on the toggle while the app is dark: tap for light. */
export function SunIcon(props: IconProps) {
    return (
        <svg {...base} {...props}>
            <circle cx="12" cy="12" r="4" />
            <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41" />
        </svg>
    );
}

/** Shown on the toggle while the app is light: tap for dark. */
export function MoonIcon(props: IconProps) {
    return (
        <svg {...base} {...props}>
            <path d="M21 12.79A9 9 0 1 1 11.21 3a7 7 0 0 0 9.79 9.79z" />
        </svg>
    );
}

/** Beside a tenant's opening hours and a menu's service window. */
export function ClockIcon(props: IconProps) {
    return (
        <svg {...base} {...props}>
            <circle cx="12" cy="12" r="9" />
            <path d="M12 7v5l3 2" />
        </svg>
    );
}

/** The back arrow out of a menu or a document. */
export function ChevronLeftIcon(props: IconProps) {
    return (
        <svg {...base} {...props}>
            <path d="m15 18-6-6 6-6" />
        </svg>
    );
}

/** The language toggle. */
export function LanguagesIcon(props: IconProps) {
    return (
        <svg {...base} {...props}>
            <circle cx="12" cy="12" r="9" />
            <path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18" />
        </svg>
    );
}

/** One more of something. */
export function PlusIcon(props: IconProps) {
    return (
        <svg {...base} {...props}>
            <path d="M12 5v14M5 12h14" />
        </svg>
    );
}

/** One fewer of something. */
export function MinusIcon(props: IconProps) {
    return (
        <svg {...base} {...props}>
            <path d="M5 12h14" />
        </svg>
    );
}

/** Closes a sheet, or takes a line out of the basket. */
export function XIcon(props: IconProps) {
    return (
        <svg {...base} {...props}>
            <path d="M18 6 6 18M6 6l12 12" />
        </svg>
    );
}

/** Inside a ticked checkbox. */
export function CheckIcon(props: IconProps) {
    return (
        <svg {...base} {...props}>
            <path d="M20 6 9 17l-5-5" />
        </svg>
    );
}

/** The dot inside a chosen radio. */
export function CircleIcon(props: IconProps) {
    return (
        <svg {...base} {...props}>
            <circle cx="12" cy="12" r="6" fill="currentColor" />
        </svg>
    );
}

/** The basket bar. */
export function BagIcon(props: IconProps) {
    return (
        <svg {...base} {...props}>
            <path d="M6 7h12l1 13H5z" />
            <path d="M9 7a3 3 0 0 1 6 0" />
        </svg>
    );
}

/** Marks the row of items a menu leads with. */
export function StarIcon(props: IconProps) {
    return (
        <svg {...base} {...props}>
            <path d="m12 3 2.7 5.5 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1L3.2 9.4l6.1-.9z" />
        </svg>
    );
}

/** Inside the vegan diet mark, so it is never read off its colour alone. */
export function LeafIcon(props: IconProps) {
    return (
        <svg {...base} {...props}>
            <path d="M20 4C9 5 4 11 4 19v1" />
            <path d="M20 4c1 9-4 14-12 14H4" />
        </svg>
    );
}

/** Beside a service request: the bell on a front desk. */
export function BellIcon(props: IconProps) {
    return (
        <svg {...base} {...props}>
            <path d="M10 6h4M12 6v4" />
            <path d="M4 18a8 8 0 0 1 16 0" />
            <path d="M2 18h20" />
        </svg>
    );
}
