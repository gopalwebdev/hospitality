import { BellIcon, LeafIcon } from '@/components/icons';
import { cn } from '@/lib/utils';

/** The veg / vegan / egg / non-veg mark an item carries. */
export type Diet = 'vegetarian' | 'vegan' | 'egg' | 'non-vegetarian';

/** What a menu item is — App\Enums\MenuItemKind. Only Consumable carries a diet. */
export type MenuItemKind = 'consumable' | 'goods' | 'service';

const RING: Record<Diet, string> = {
    vegetarian: 'border-green-600',
    vegan: 'border-teal-600',
    egg: 'border-amber-500',
    'non-vegetarian': 'border-red-600',
};

const DOT: Record<Diet, string> = {
    vegetarian: 'bg-green-600',
    vegan: 'bg-teal-600',
    egg: 'bg-amber-500',
    'non-vegetarian': 'bg-red-600',
};

const LABEL: Record<Diet, string> = {
    vegetarian: 'Vegetarian',
    vegan: 'Vegan',
    egg: 'Contains egg',
    'non-vegetarian': 'Non-vegetarian',
};

/**
 * The mark beside an item's name: what it is, at a glance.
 *
 * A consumable carries the square-and-dot diet mark Indian menus use. It is
 * deliberately not themed: guests read it at a glance, and its colours mean a
 * fixed thing.
 *
 * Vegan carries a leaf in that square rather than a dot, and a teal square
 * rather than a green one. Two cues rather than one, because a green square
 * with a dot and a green square with a leaf are the same thing at 16px, and a
 * guest who keeps vegan is the one person this mark has to be exact for.
 *
 * A Service has no diet, and carries a bell in the same square instead, in a
 * colour none of the diets use so it is never read as one. Goods — a towel, a
 * bottle nobody has marked — needs no mark at all, and keeps the space
 * instead, hidden from screen readers so its name still starts at the same
 * edge. A basket line the menu no longer lists reads the same way.
 */
export function ItemMark({
    kind,
    diet,
    className,
}: {
    kind: MenuItemKind;
    diet: Diet | null;
    className?: string;
}) {
    if (kind === 'service') {
        return (
            <span
                role="img"
                aria-label="Service"
                title="Service"
                className={cn(
                    'inline-flex size-4 shrink-0 items-center justify-center rounded-[3px] border-2 border-sky-600 text-sky-600',
                    className,
                )}
            >
                <BellIcon className="size-2.5" strokeWidth={3} />
            </span>
        );
    }

    if (kind === 'goods' || diet === null) {
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
            {diet === 'vegan' ? (
                <LeafIcon className="size-2.5 text-teal-600" strokeWidth={3} />
            ) : (
                <span className={cn('size-2 rounded-full', DOT[diet])} />
            )}
        </span>
    );
}
