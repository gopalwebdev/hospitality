import { cn } from '@/lib/utils';

export type FoodType = 'vegetarian' | 'egg' | 'non-vegetarian';

const RING: Record<FoodType, string> = {
    vegetarian: 'border-green-600',
    egg: 'border-amber-500',
    'non-vegetarian': 'border-red-600',
};

const DOT: Record<FoodType, string> = {
    vegetarian: 'bg-green-600',
    egg: 'bg-amber-500',
    'non-vegetarian': 'bg-red-600',
};

const LABEL: Record<FoodType, string> = {
    vegetarian: 'Vegetarian',
    egg: 'Contains egg',
    'non-vegetarian': 'Non-vegetarian',
};

/**
 * The square-and-dot mark Indian menus carry beside every dish.
 *
 * Deliberately not themed: this is a regulatory mark that guests read at a
 * glance, and its colours mean a fixed thing. It must not follow the
 * tenant's brand colour.
 */
export function FoodTypeDot({
    type,
    className,
}: {
    type: FoodType;
    className?: string;
}) {
    return (
        <span
            role="img"
            aria-label={LABEL[type]}
            title={LABEL[type]}
            className={cn(
                'inline-flex size-4 shrink-0 items-center justify-center border-2',
                RING[type],
                className,
            )}
        >
            <span className={cn('size-2 rounded-full', DOT[type])} />
        </span>
    );
}
