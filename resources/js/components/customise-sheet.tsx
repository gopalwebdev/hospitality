import { useId, useState } from 'react';

import { ItemMark, type Diet } from '@/components/item-mark';
import { QuantityStepper } from '@/components/quantity-stepper';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useMoney } from '@/hooks/use-money';
import { useTranslations } from '@/hooks/use-translations';
import {
    type AddOnGroup,
    type AddOnOption,
    type Choice,
    type Picks,
    canAddOne,
    choicesOf,
    choose,
    firstShortfall,
    hasRoomIn,
    initialPicks,
    isRequired,
    isSingleChoice,
    ruleOf,
    step,
    toggle,
    unitPrice,
} from '@/lib/add-on-rules';
import { type OrderLimits, limitsRule, roomFor } from '@/lib/order-limits';

/** What the sheet needs of the item being customised. */
export interface CustomisableItem extends OrderLimits {
    id: number;
    name: string;
    description: string | null;
    price: number;
    isServiceRequest: boolean;
    diet: Diet | null;
}

interface CustomiseSheetProps {
    /** The item being customised; the sheet is closed while this is null. */
    item: CustomisableItem | null;
    /** The groups it offers, in the order the item lists them. */
    groups: AddOnGroup[];
    /** How many of it the basket already holds, which counts towards its maximum. */
    held: number;
    onClose: () => void;
    onAdd: (choices: Choice[], quantity: number) => void;
}

/**
 * Where a guest customises an item before it goes in the basket.
 *
 * From the bottom of the screen, where a thumb already is. Each group says what
 * it asks for and whether it is required, and Add says what is still missing
 * rather than simply refusing — "Choose 1 more from Bread" is something to do.
 */
export function CustomiseSheet({
    item,
    groups,
    held,
    onClose,
    onAdd,
}: CustomiseSheetProps) {
    return (
        <Sheet
            open={item !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
        >
            <SheetContent
                side="bottom"
                className="mx-auto max-h-[90dvh] w-full max-w-[26rem] gap-0 rounded-t-2xl"
            >
                {/* Keyed on the item, so the picks start again for each one. */}
                {item !== null && (
                    <Customiser
                        key={item.id}
                        item={item}
                        groups={groups}
                        held={held}
                        onAdd={onAdd}
                    />
                )}
            </SheetContent>
        </Sheet>
    );
}

function Customiser({
    item,
    groups,
    held,
    onAdd,
}: {
    item: CustomisableItem;
    groups: AddOnGroup[];
    held: number;
    onAdd: CustomiseSheetProps['onAdd'];
}) {
    const { t } = useTranslations();
    const money = useMoney();
    // What the basket already holds counts towards the item's maximum, so
    // another line of it stops where the maximum does.
    const room = roomFor(item, held);
    const rule = limitsRule(item);
    const [picks, setPicks] = useState<Picks>(() => initialPicks(groups));
    const [quantity, setQuantity] = useState(1);

    const shortfall = firstShortfall(groups, picks);
    const total = unitPrice(item.price, groups, picks) * quantity;

    return (
        <>
            <SheetHeader className="border-b px-5 pt-5 pb-4 text-left">
                <div className="flex items-start gap-3 pr-8">
                    <ItemMark
                        diet={item.diet}
                        isServiceRequest={item.isServiceRequest}
                        className="mt-1.5"
                    />

                    <div className="min-w-0">
                        <SheetTitle className="text-lg leading-snug">
                            {item.name}
                        </SheetTitle>

                        {/* Always there, seen or not: a dialog is announced
                            with its description. */}
                        <SheetDescription
                            className={
                                item.description === null
                                    ? 'sr-only'
                                    : undefined
                            }
                        >
                            {item.description ?? t('menu.customisable')}
                        </SheetDescription>
                    </div>
                </div>
            </SheetHeader>

            <div className="min-h-0 flex-1 divide-y overflow-y-auto overscroll-contain">
                {groups.map((group) => (
                    <GroupChoices
                        key={group.id}
                        group={group}
                        picks={picks}
                        onChange={setPicks}
                    />
                ))}
            </div>

            <SheetFooter className="border-t px-5 pt-3 pb-[max(1rem,env(safe-area-inset-bottom))]">
                {rule !== null && (
                    <p className="text-muted-foreground text-xs">
                        {t(rule.path, rule.replacements)}
                    </p>
                )}

                <div className="flex items-center gap-3">
                    <QuantityStepper
                        value={quantity}
                        name={item.name}
                        canDecrease={quantity > 1}
                        canIncrease={quantity < room}
                        onDecrease={() => {
                            setQuantity(quantity - 1);
                        }}
                        onIncrease={() => {
                            setQuantity(quantity + 1);
                        }}
                    />

                    <Button
                        type="button"
                        size="lg"
                        className="h-auto min-h-12 flex-1 whitespace-normal"
                        disabled={shortfall !== null || quantity > room}
                        onClick={() => {
                            onAdd(choicesOf(groups, picks), quantity);
                        }}
                    >
                        {shortfall === null
                            ? t('customise.add', { price: money(total) })
                            : t('customise.choose_more', {
                                  count: shortfall.missing,
                                  group: shortfall.group.name,
                              })}
                    </Button>
                </div>
            </SheetFooter>
        </>
    );
}

interface GroupChoicesProps {
    group: AddOnGroup;
    picks: Picks;
    onChange: (picks: Picks) => void;
}

/**
 * One group: its name, whether it must be answered, its rule, and its options.
 *
 * A required pick-one is radios, which is what a guest expects of "choose 1".
 * Anything else is checkboxes, with a stepper on an option a guest may take
 * more than one of; once the group is full, the options not picked stop
 * offering themselves.
 */
function GroupChoices({ group, picks, onChange }: GroupChoicesProps) {
    const { t } = useTranslations();
    const headingId = useId();
    const rule = ruleOf(group);
    const required = isRequired(group);

    const chosen = group.options.find((option) => (picks[option.id] ?? 0) > 0);

    return (
        <section className="px-5 py-4" aria-labelledby={headingId}>
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h3 id={headingId} className="leading-snug font-medium">
                        {group.name}
                    </h3>
                    <p className="text-muted-foreground text-xs">
                        {t(rule.path, rule.replacements)}
                    </p>
                </div>

                <Badge variant={required ? 'default' : 'secondary'}>
                    {required
                        ? t('customise.required')
                        : t('customise.optional')}
                </Badge>
            </div>

            {isSingleChoice(group) ? (
                <RadioGroup
                    className="mt-2 gap-0"
                    aria-labelledby={headingId}
                    value={chosen === undefined ? '' : String(chosen.id)}
                    onValueChange={(value) => {
                        const option = group.options.find(
                            (candidate) => String(candidate.id) === value,
                        );

                        if (option !== undefined) {
                            onChange(choose(group, option, picks));
                        }
                    }}
                >
                    {group.options.map((option) => (
                        <RadioOption key={option.id} option={option} />
                    ))}
                </RadioGroup>
            ) : (
                <div className="mt-2" role="group" aria-labelledby={headingId}>
                    {group.options.map((option) => (
                        <CheckboxOption
                            key={option.id}
                            group={group}
                            option={option}
                            picks={picks}
                            onChange={onChange}
                        />
                    ))}
                </div>
            )}
        </section>
    );
}

function RadioOption({ option }: { option: AddOnOption }) {
    const nameId = useId();

    return (
        <label className="flex min-h-12 items-center gap-3 py-2">
            <RadioGroupItem
                value={String(option.id)}
                className="size-5"
                aria-labelledby={nameId}
            />
            <span id={nameId} className="min-w-0 flex-1">
                {option.name}
            </span>
            <OptionPrice option={option} />
        </label>
    );
}

function CheckboxOption({
    group,
    option,
    picks,
    onChange,
}: GroupChoicesProps & { option: AddOnOption }) {
    const { t } = useTranslations();
    const nameId = useId();
    const quantity = picks[option.id] ?? 0;
    const isPicked = quantity > 0;
    // A group of one pick moves its tick instead, so it never locks.
    const isFull =
        !isPicked && group.maxPicks !== 1 && !hasRoomIn(group, picks);

    return (
        <div className="flex min-h-12 items-center gap-3 py-1">
            <label className="flex min-w-0 flex-1 items-center gap-3 py-2">
                <Checkbox
                    className="size-5"
                    checked={isPicked}
                    disabled={isFull}
                    aria-labelledby={nameId}
                    onCheckedChange={() => {
                        onChange(toggle(group, option, picks));
                    }}
                />
                <span className="min-w-0 flex-1">
                    <span
                        id={nameId}
                        className={isFull ? 'text-muted-foreground' : undefined}
                    >
                        {option.name}
                    </span>
                    {/* Outside the labelled span, so it reads as part of the row
                        rather than the checkbox's own accessible name — and a
                        guest sees it before they tick, not only once the
                        stepper appears. */}
                    {option.maxPerItem > 1 && (
                        <span className="text-muted-foreground ml-1.5 text-xs">
                            {t('customise.option_up_to', {
                                count: option.maxPerItem,
                            })}
                        </span>
                    )}
                </span>
            </label>

            {isPicked && option.maxPerItem > 1 && (
                <QuantityStepper
                    compact
                    value={quantity}
                    name={option.name}
                    canDecrease
                    canIncrease={canAddOne(group, option, picks)}
                    onDecrease={() => {
                        onChange(step(group, option, -1, picks));
                    }}
                    onIncrease={() => {
                        onChange(step(group, option, 1, picks));
                    }}
                />
            )}

            <OptionPrice option={option} />
        </div>
    );
}

/**
 * What one of an option adds. Nothing is shown for one that adds nothing: beside
 * Mild, Medium and Hot, "Free" three times over is noise.
 */
function OptionPrice({ option }: { option: AddOnOption }) {
    const money = useMoney();

    if (option.price === 0) {
        return null;
    }

    return (
        <span className="text-muted-foreground shrink-0 text-sm tabular-nums">
            {`+ ${money(option.price)}`}
        </span>
    );
}
