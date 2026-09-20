import { BagIcon } from '@/components/icons';
import { Money } from '@/components/money';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';

interface BasketBarProps {
    /** Everything in the basket, each line counted by its quantity. */
    count: number;
    /** What the basket comes to, or null until the server has said. */
    total: number | null;
    onOpen: () => void;
}

/**
 * The way into the basket, held at the bottom of the screen once there is something in it.
 *
 * Sticky rather than fixed, so it stays inside the phone-width frame on a wider
 * screen and never covers the small print at the end of the menu.
 *
 * It carries the total as well as the count, because "5 items" alone is not
 * what a guest wants to know before they decide to look — and the number is the
 * server's, priced as soon as the basket has anything in it rather than waiting
 * for the sheet to be opened (`hooks/use-basket-price.ts`). Until that first
 * answer lands the line is held with a dash, so the bar does not change height
 * underneath a thumb that is already reaching for it.
 */
export function BasketBar({ count, total, onOpen }: BasketBarProps) {
    const { t } = useTranslations();

    return (
        <div className="bg-background/95 sticky bottom-0 z-10 border-t px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] backdrop-blur">
            <Button
                type="button"
                size="lg"
                className="h-14 w-full justify-between rounded-full px-5"
                onClick={onOpen}
            >
                <span className="flex items-center gap-3">
                    <BagIcon className="size-5 shrink-0" />

                    <span className="flex flex-col items-start leading-tight">
                        <span className="text-xs font-normal opacity-80">
                            {count === 1
                                ? t('basket.one_item')
                                : t('basket.items', { count })}
                        </span>

                        {total === null ? (
                            <span className="text-base font-semibold tabular-nums">
                                —
                            </span>
                        ) : (
                            <Money
                                amount={total}
                                className="text-base font-semibold"
                            />
                        )}
                    </span>
                </span>

                <span>{t('basket.view')}</span>
            </Button>
        </div>
    );
}
