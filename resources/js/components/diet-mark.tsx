import { cn } from '@/lib/utils';

/** The veg / egg / non-veg mark an item carries. */
export type Diet = 'vegetarian' | 'egg' | 'non-vegetarian';

const RING: Record<Diet, string> = {
    vegetarian: 'border-green-600',
    egg: 'border-amber-500',
    'non-vegetarian': 'border-red-600',
};

const DOT: Record<Diet, string> = {
    vegetarian: 'bg-green-600',
    egg: 'bg-amber-500',
    'non-vegetarian': 'bg-red-600',
};

const LABEL: Record<Diet, string> = {
    vegetarian: 'Vegetarian',
    egg: 'Contains egg',
    'non-vegetarian': 'Non-vegetarian',
};

/**
 * The square-and-dot mark Indian menus carry beside an item.
 *
 * Deliberately not themed: this is a regulatory mark that guests read at a
 * glance, and its colours mean a fixed thing. It must not follow the tenant's
 * brand colour.
 *
 * A service request has no diet to declare, and gets the mark's empty space
 * instead: leaving the space out would start its name further left than every
 * item around it. The space is hidden from screen readers.
 */
export function DietMark({
    diet,
    className,
}: {
    diet: Diet | null;
    className?: string;
}) {
    if (diet === null) {
        return (
            <span
                aria-hidden="true"
                className={cn('size-4 shrink-0', className)}
            />
        );
    }

    return (
        <span
            role="img"
            aria-label={LABEL[diet]}
            title={LABEL[diet]}
            className={cn(
                'inline-flex size-4 shrink-0 items-center justify-center border-2',
                RING[diet],
                className,
            )}
        >
            <span className={cn('size-2 rounded-full', DOT[diet])} />
        </span>
    );
}
