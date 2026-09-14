import { XIcon } from '@/components/icons';
import { ItemMark, type Diet } from '@/components/item-mark';
import { QuantityStepper } from '@/components/quantity-stepper';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import {
    type Basket,
    type BasketLine,
    MAX_LINE_QUANTITY,
} from '@/hooks/use-basket';
import {
    type Quote,
    type QuotedLine,
    useBasketQuote,
} from '@/hooks/use-basket-quote';
import { useMoney } from '@/hooks/use-money';
import { useTranslations } from '@/hooks/use-translations';

/** How a basket line reads: its name, its diet mark, and what it was customised with. */
export interface LineDescription {
    name: string;
    diet: Diet | null;
    isServiceRequest: boolean;
    /** Each picked option, "2 × Extra cheese" where more than one was taken. */
    choices: string[];
}

interface BasketSheetProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    basket: Basket;
    quoteUrl: string;
    describe: (line: BasketLine) => LineDescription;
}

/**
 * What is in the basket, and what it comes to.
 *
 * Every number here is the server's (App\Actions\Menus\QuoteBasket) — each line,
 * the GST, each charge and the total — asked for again whenever the sheet is
 * open and the basket changes. A line the menu can no longer honour says so in
 * place and is left out of the total until it is changed or removed.
 *
 * Nothing is ordered from here yet: a guest shows this to a member of staff.
 */
export function BasketSheet({
    open,
    onOpenChange,
    basket,
    quoteUrl,
    describe,
}: BasketSheetProps) {
    const { t } = useTranslations();
    const { quote, isPricing } = useBasketQuote(quoteUrl, basket.lines, open);

    const quoted = new Map(
        (quote?.lines ?? []).map((line) => [line.key, line]),
    );

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="bottom"
                className="mx-auto max-h-[90dvh] w-full max-w-[26rem] gap-0 rounded-t-2xl"
            >
                <SheetHeader className="border-b px-5 pt-5 pb-4 text-left">
                    <SheetTitle className="text-lg">
                        {t('basket.title')}
                    </SheetTitle>
                    <SheetDescription>{t('basket.hint')}</SheetDescription>
                </SheetHeader>

                {basket.lines.length === 0 ? (
                    <p className="text-muted-foreground px-5 py-12 text-center text-sm">
                        {t('basket.empty')}
                    </p>
                ) : (
                    <>
                        <ul className="min-h-0 flex-1 divide-y overflow-y-auto overscroll-contain">
                            {basket.lines.map((line) => (
                                <LineRow
                                    key={line.key}
                                    line={line}
                                    description={describe(line)}
                                    quoted={quoted.get(line.key)}
                                    basket={basket}
                                />
                            ))}
                        </ul>

                        <SheetFooter className="gap-3 border-t px-5 pt-3 pb-[max(1rem,env(safe-area-inset-bottom))]">
                            <Totals quote={quote} isPricing={isPricing} />

                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="self-start"
                                onClick={() => {
                                    basket.clear();
                                }}
                            >
                                {t('basket.clear')}
                            </Button>
                        </SheetFooter>
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}

function LineRow({
    line,
    description,
    quoted,
    basket,
}: {
    line: BasketLine;
    description: LineDescription;
    quoted: QuotedLine | undefined;
    basket: Basket;
}) {
    const { t } = useTranslations();
    const money = useMoney();

    const problem =
        quoted?.status === 'unavailable'
            ? t('basket.unavailable')
            : quoted?.status === 'invalid'
              ? t('basket.invalid')
              : null;

    return (
        <li className="flex items-start gap-3 px-5 py-4">
            <ItemMark
                diet={description.diet}
                isServiceRequest={description.isServiceRequest}
                className="mt-1"
            />

            <div className="min-w-0 flex-1">
                <p className="leading-snug font-medium">{description.name}</p>

                {description.choices.length > 0 && (
                    <p className="text-muted-foreground mt-0.5 text-sm leading-snug">
                        {description.choices.join(', ')}
                    </p>
                )}

                {problem !== null && (
                    <p className="text-destructive mt-1 text-sm font-medium">
                        {problem}
                    </p>
                )}

                <div className="mt-2 flex items-center gap-1">
                    <QuantityStepper
                        compact
                        value={line.quantity}
                        name={description.name}
                        canDecrease={line.quantity > 1}
                        canIncrease={line.quantity < MAX_LINE_QUANTITY}
                        onDecrease={() => {
                            basket.setQuantity(line.key, line.quantity - 1);
                        }}
                        onIncrease={() => {
                            basket.setQuantity(line.key, line.quantity + 1);
                        }}
                    />

                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        aria-label={t('basket.remove', {
                            name: description.name,
                        })}
                        onClick={() => {
                            basket.remove(line.key);
                        }}
                    >
                        <XIcon />
                    </Button>
                </div>
            </div>

            {quoted?.status === 'ok' &&
                (quoted.totalMinorUnits === 0 ? (
                    <p className="text-muted-foreground shrink-0 text-sm font-medium">
                        {t('menu.complimentary')}
                    </p>
                ) : (
                    <p className="shrink-0 font-semibold tabular-nums">
                        {money(quoted.totalMinorUnits)}
                    </p>
                ))}
        </li>
    );
}

/**
 * The bill as the server worked it out.
 *
 * GST is its own line when it is added on top, and a note under the total when
 * the prices already include it.
 */
function Totals({
    quote,
    isPricing,
}: {
    quote: Quote | null;
    isPricing: boolean;
}) {
    const { t } = useTranslations();
    const money = useMoney();

    if (quote === null) {
        return (
            <p className="text-muted-foreground text-sm" aria-live="polite">
                {t('basket.pricing')}
            </p>
        );
    }

    return (
        // Dimmed rather than blanked while the next answer is fetched, so a
        // tap on a stepper does not make the total vanish and come back.
        <div
            className={isPricing ? 'opacity-60' : undefined}
            aria-live="polite"
            aria-busy={isPricing}
        >
            <dl className="space-y-1 text-sm tabular-nums">
                <TotalRow
                    label={t('basket.subtotal')}
                    amount={money(quote.subtotalMinorUnits)}
                />

                {!quote.pricesIncludeTax && quote.taxMinorUnits > 0 && (
                    <TotalRow
                        label={t('basket.gst')}
                        amount={money(quote.taxMinorUnits)}
                    />
                )}

                {quote.charges.map((charge) => (
                    <TotalRow
                        key={charge.id}
                        label={charge.name}
                        amount={money(charge.amountMinorUnits)}
                    />
                ))}

                <TotalRow
                    strong
                    label={t('basket.total')}
                    amount={money(quote.totalMinorUnits)}
                />
            </dl>

            {quote.pricesIncludeTax && quote.taxMinorUnits > 0 && (
                <p className="text-muted-foreground mt-1 text-xs">
                    {t('basket.gst_included', {
                        amount: money(quote.taxMinorUnits),
                    })}
                </p>
            )}
        </div>
    );
}

function TotalRow({
    label,
    amount,
    strong = false,
}: {
    label: string;
    amount: string;
    strong?: boolean;
}) {
    return (
        <div
            className={`flex justify-between gap-3 ${
                strong ? 'text-base font-semibold' : 'text-muted-foreground'
            }`}
        >
            <dt>{label}</dt>
            <dd>{amount}</dd>
        </div>
    );
}
