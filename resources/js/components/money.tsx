import { useCountUp } from '@/hooks/use-count-up';
import { useMoney } from '@/hooks/use-money';

interface MoneyProps {
    /** An integer count of the currency's minor unit, as the server sends it. */
    amount: number;
    className?: string;
}

/**
 * An amount of money that counts to its new value instead of swapping to it.
 *
 * Everywhere a figure can change under the guest — a basket line, the totals,
 * the bar at the foot of the menu — so a new answer from the server reads as
 * movement rather than a flicker. `tabular-nums` is on by default: without it
 * every frame of the count would be a different width and the row would jitter.
 *
 * A figure that cannot change, such as a price on the menu itself, does not
 * need this and should go through `useMoney()` directly.
 */
export function Money({ amount, className = '' }: MoneyProps) {
    const money = useMoney();
    const shown = useCountUp(amount);

    return (
        <span className={`tabular-nums ${className}`.trim()}>
            {money(shown)}
        </span>
    );
}
