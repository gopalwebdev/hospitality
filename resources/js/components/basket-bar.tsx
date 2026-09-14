import { BagIcon } from '@/components/icons';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';

interface BasketBarProps {
    /** Everything in the basket, each line counted by its quantity. */
    count: number;
    onOpen: () => void;
}

/**
 * The way into the basket, held at the bottom of the screen once there is something in it.
 *
 * Sticky rather than fixed, so it stays inside the phone-width frame on a wider
 * screen and never covers the small print at the end of the menu.
 */
export function BasketBar({ count, onOpen }: BasketBarProps) {
    const { t } = useTranslations();

    return (
        <div className="bg-background/95 sticky bottom-0 z-10 border-t px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] backdrop-blur">
            <Button
                type="button"
                size="lg"
                className="h-12 w-full justify-between rounded-full px-5"
                onClick={onOpen}
            >
                <span className="flex items-center gap-2">
                    <BagIcon className="size-5" />
                    {count === 1
                        ? t('basket.one_item')
                        : t('basket.items', { count })}
                </span>
                <span>{t('basket.view')}</span>
            </Button>
        </div>
    );
}
