import { MinusIcon, PlusIcon } from '@/components/icons';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';

interface QuantityStepperProps {
    value: number;
    /** What is being counted, which names the buttons: "One more Garlic naan". */
    name: string;
    canDecrease: boolean;
    canIncrease: boolean;
    onDecrease: () => void;
    onIncrease: () => void;
    /** Smaller inside a row of options than for a whole line. */
    compact?: boolean;
}

/**
 * A count with a button either side: how many of an item, or of one option.
 *
 * Buttons rather than a number field, because opening a phone's keyboard to
 * turn 1 into 2 is three taps too many.
 */
export function QuantityStepper({
    value,
    name,
    canDecrease,
    canIncrease,
    onDecrease,
    onIncrease,
    compact = false,
}: QuantityStepperProps) {
    const { t } = useTranslations();
    const buttonClassName = compact
        ? 'size-8 rounded-full'
        : 'size-10 rounded-full';

    return (
        <div className="flex shrink-0 items-center rounded-full border">
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className={buttonClassName}
                aria-label={t('customise.decrease', { name })}
                disabled={!canDecrease}
                onClick={onDecrease}
            >
                <MinusIcon />
            </Button>

            <span
                className="min-w-6 text-center text-sm font-medium tabular-nums"
                aria-live="polite"
            >
                {value}
            </span>

            <Button
                type="button"
                variant="ghost"
                size="icon"
                className={buttonClassName}
                aria-label={t('customise.increase', { name })}
                disabled={!canIncrease}
                onClick={onIncrease}
            >
                <PlusIcon />
            </Button>
        </div>
    );
}
