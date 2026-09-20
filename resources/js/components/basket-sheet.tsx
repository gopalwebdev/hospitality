import { XIcon } from '@/components/icons';
import { ItemMark, type Diet } from '@/components/item-mark';
import { Money } from '@/components/money';
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
    type PricedBasket,
    type PricedLine,
    type TaxParts,
} from '@/hooks/use-basket-price';
import { useMoney } from '@/hooks/use-money';
import { type Translator, useTranslations } from '@/hooks/use-translations';
import {
    type OrderLimits,
    isWithinLimits,
    limitsRule,
    quantityHeld,
    roomFor,
} from '@/lib/order-limits';
import { formatRate, wholeRate } from '@/lib/rate';

/** How a basket line reads: its name, its diet mark, and what it was customised with. */
export interface LineDescription {
    name: string;
    diet: Diet | null;
    isServiceRequest: boolean;
    /** The most of the item or combo one order may hold. */
    limits: OrderLimits;
    /** Each picked option, "2 × Extra cheese" where more than one was taken. */
    choices: string[];
}

interface BasketSheetProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    basket: Basket;
    /** The server's answer, or null until it has given one. */
    priced: PricedBasket | null;
    /** Whether the next answer is on its way, which assistive tech is told. */
    isPricing: boolean;
    describe: (line: BasketLine) => LineDescription;
}

/**
 * What is in the basket, and what it comes to.
 *
 * Every number here is the server's (App\Actions\Baskets\PriceBasket) — each
 * line, its GST, each charge and the total. The menu page owns the asking, so
 * the same answer feeds this sheet and the bar at the foot of the menu; this
 * component only reads it. A line the menu can no longer honour says so in
 * place and is left out of the total until it is changed or removed.
 *
 * GST is shown twice over, which is what a bill here does. The **rate and
 * amount on each line**, because one basket can hold a 5% item beside an 18%
 * one and a single figure at the foot would hide that. And the **parts at the
 * foot** — CGST and SGST, or UTGST, or one IGST — because that is how the tax
 * is actually levied and how it has to be shown.
 *
 * Nothing is ordered from here: a guest shows this to a member of staff.
 */
export function BasketSheet({
    open,
    onOpenChange,
    basket,
    priced,
    isPricing,
    describe,
}: BasketSheetProps) {
    const { t } = useTranslations();

    const pricedLines = new Map(
        (priced?.lines ?? []).map((line) => [line.key, line]),
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
                                    priced={pricedLines.get(line.key)}
                                    pricesIncludeTax={
                                        priced?.pricesIncludeTax ?? false
                                    }
                                    basket={basket}
                                />
                            ))}
                        </ul>

                        <SheetFooter className="gap-3 border-t px-5 pt-3 pb-[max(1rem,env(safe-area-inset-bottom))]">
                            <Totals priced={priced} isPricing={isPricing} />

                            {/* Worded as the action it is and set apart from
                                the totals, because "Empty basket" sitting
                                under a total read as a statement that the
                                basket was empty. */}
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="text-muted-foreground hover:text-destructive self-center"
                                onClick={() => {
                                    basket.clear();
                                }}
                            >
                                <XIcon className="size-4" />
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
    priced,
    pricesIncludeTax,
    basket,
}: {
    line: BasketLine;
    description: LineDescription;
    priced: PricedLine | undefined;
    pricesIncludeTax: boolean;
    basket: Basket;
}) {
    const { t } = useTranslations();
    const money = useMoney();

    // Counted across every line the item or combo is on, as the server counts.
    const held = quantityHeld(basket.lines, line.type, line.id);
    const rule = limitsRule(description.limits);

    // A line refused for holding too many says how many one order may hold,
    // which is what the guest has to change.
    const problem =
        priced?.status === 'unavailable'
            ? t('basket.unavailable')
            : priced?.status === 'invalid'
              ? rule !== null && !isWithinLimits(description.limits, held)
                  ? t(rule.path, rule.replacements)
                  : t('basket.invalid')
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

                {problem !== null ? (
                    <p className="text-destructive mt-1 text-sm font-medium">
                        {problem}
                    </p>
                ) : (
                    rule !== null && (
                        <p className="text-muted-foreground mt-0.5 text-xs">
                            {t(rule.path, rule.replacements)}
                        </p>
                    )
                )}

                <div className="mt-2 flex items-center gap-1">
                    <QuantityStepper
                        compact
                        value={line.quantity}
                        name={description.name}
                        canDecrease={line.quantity > 1}
                        canIncrease={
                            line.quantity < MAX_LINE_QUANTITY &&
                            roomFor(description.limits, held) > 0
                        }
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

            {priced?.status === 'ok' && (
                <div className="shrink-0 text-right">
                    {priced.total === 0 ? (
                        <p className="text-muted-foreground text-sm font-medium">
                            {t('menu.complimentary')}
                        </p>
                    ) : (
                        <p className="font-semibold">
                            <Money amount={priced.total} />
                        </p>
                    )}

                    {/* A complimentary line is taxed at nothing, so it carries
                        no GST line either — there is no bill to explain. */}
                    {priced.tax > 0 && (
                        <p className="text-muted-foreground mt-0.5 text-xs tabular-nums">
                            {t(
                                pricesIncludeTax
                                    ? 'basket.line_gst_included'
                                    : 'basket.line_gst',
                                {
                                    rate: formatRate(
                                        wholeRate(priced.taxParts),
                                    ),
                                    amount: money(priced.tax),
                                },
                            )}
                        </p>
                    )}
                </div>
            )}
        </li>
    );
}

/**
 * The parts of a bill's GST, each on its own line, in the order a bill lists them.
 *
 * Only the parts that carry something: an intra-state bill never shows an empty
 * IGST, and a tenant charging no GST shows no rows at all rather than three
 * zeroes. The state's half is called UTGST in a union territory, where the
 * money is identical and only the wording differs.
 */
function gstRows(
    parts: TaxParts,
    t: Translator['t'],
): { key: string; label: string; amount: number }[] {
    return [
        {
            key: 'cgst',
            label: t('basket.cgst', { rate: formatRate(parts.cgstRate) }),
            amount: parts.cgst,
        },
        {
            key: 'sgst',
            label: t(
                parts.treatment === 'union-territory'
                    ? 'basket.utgst'
                    : 'basket.sgst',
                { rate: formatRate(parts.sgstRate) },
            ),
            amount: parts.sgst,
        },
        {
            key: 'igst',
            label: t('basket.igst', { rate: formatRate(parts.igstRate) }),
            amount: parts.igst,
        },
    ].filter((row) => row.amount > 0);
}

/**
 * The bill as the server worked it out.
 *
 * GST sits between the subtotal and the total when it is added on top, because
 * that is where it is added. When the prices already carry it, it moves below
 * the total as a note — it is not part of the sum there, and putting it in the
 * running list would read as though it were charged twice.
 */
function Totals({
    priced,
    isPricing,
}: {
    priced: PricedBasket | null;
    isPricing: boolean;
}) {
    const { t } = useTranslations();
    const money = useMoney();

    if (priced === null) {
        return (
            <p className="text-muted-foreground text-sm" aria-live="polite">
                {t('basket.pricing')}
            </p>
        );
    }

    const gst = gstRows(priced.taxParts, t);

    return (
        // Nothing is dimmed or blanked while the next answer is fetched. The
        // figures themselves count from the old total to the new one, which
        // shows the change without the rest of the row flickering under it.
        <div aria-live="polite" aria-busy={isPricing}>
            <dl className="space-y-1 text-sm">
                <TotalRow
                    label={t('basket.subtotal')}
                    amount={priced.subtotal}
                />

                {!priced.pricesIncludeTax &&
                    gst.map((row) => (
                        <TotalRow
                            key={row.key}
                            label={row.label}
                            amount={row.amount}
                        />
                    ))}

                {priced.charges.map((charge) => (
                    <TotalRow
                        key={charge.id}
                        label={charge.name}
                        amount={charge.amount}
                    />
                ))}

                <TotalRow
                    strong
                    label={t('basket.total')}
                    amount={priced.total}
                />
            </dl>

            {priced.pricesIncludeTax && priced.tax > 0 && (
                <div className="text-muted-foreground mt-1 text-xs">
                    <p>
                        {t('basket.gst_included', {
                            amount: money(priced.tax),
                        })}
                    </p>

                    <dl className="mt-0.5 space-y-0.5">
                        {gst.map((row) => (
                            <div
                                key={row.key}
                                className="flex justify-between gap-3"
                            >
                                <dt>{row.label}</dt>
                                <dd>
                                    <Money amount={row.amount} />
                                </dd>
                            </div>
                        ))}
                    </dl>
                </div>
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
    amount: number;
    strong?: boolean;
}) {
    return (
        <div
            className={`flex justify-between gap-3 ${
                strong ? 'text-base font-semibold' : 'text-muted-foreground'
            }`}
        >
            <dt>{label}</dt>
            <dd>
                <Money amount={amount} />
            </dd>
        </div>
    );
}
