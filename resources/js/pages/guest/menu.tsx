import { Head } from '@inertiajs/react';
import { createContext, useContext, useState } from 'react';

import { AppBar } from '@/components/app-bar';
import { BasketBar } from '@/components/basket-bar';
import { BasketSheet, type LineDescription } from '@/components/basket-sheet';
import { CustomiseSheet } from '@/components/customise-sheet';
import { PlusIcon, StarIcon } from '@/components/icons';
import { ItemMark, type Diet } from '@/components/item-mark';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import {
    type BasketLine,
    type BasketLineType,
    basketStorageKey,
    useBasket,
} from '@/hooks/use-basket';
import { useMoney } from '@/hooks/use-money';
import { useTranslations } from '@/hooks/use-translations';
import type { AddOnGroup } from '@/lib/add-on-rules';
import { type OrderLimits, quantityHeld, roomFor } from '@/lib/order-limits';

export interface MenuItem extends OrderLimits {
    id: number;
    name: string;
    description: string | null;
    /** An integer count of the currency's minor unit; 0 means complimentary. */
    priceMinorUnits: number;
    /** A higher price to show struck through, or null when not on offer. */
    compareAtPriceMinorUnits: number | null;
    /** A service request — an extra pillow — rather than something to order. */
    isServiceRequest: boolean;
    /** Null for a service request, which carries a bell mark instead. */
    diet: Diet | null;
    /**
     * The add-on groups it is customised with, in the order a guest reads them.
     * Each is one of the page's `addOnGroups`.
     */
    addOnGroupIds: number[];
}

interface ComboContent {
    id: number;
    name: string;
    isServiceRequest: boolean;
    diet: Diet | null;
    quantity: number;
}

export interface Combo extends OrderLimits {
    id: number;
    name: string;
    description: string | null;
    priceMinorUnits: number;
    compareAtPriceMinorUnits: number | null;
    contents: ComboContent[];
}

interface SubSection {
    id: number;
    name: string;
    items: MenuItem[];
}

export interface Section {
    id: number;
    name: string;
    /** Items filed straight under the category, above its subdivisions. */
    items: MenuItem[];
    subSections: SubSection[];
}

export interface Tax {
    /** Basis points: 500 is 5%. */
    rateBasisPoints: number;
    pricesIncludeTax: boolean;
}

/**
 * Something added to a bill from this menu: a share of it, or a fixed amount.
 *
 * Exactly one of the two numbers is set.
 */
export interface Charge {
    id: number;
    name: string;
    /** Basis points: 1000 is 10%. */
    rateBasisPoints: number | null;
    /** An integer count of the currency's minor unit. */
    amountMinorUnits: number | null;
}

interface MenuProps {
    tenant: { name: string; slug: string } | null;
    menu: {
        id: number;
        name: string;
        description: string | null;
        servedFrom: string | null;
        servedUntil: string | null;
        isBeingServed: boolean;
    };
    /** The items this menu leads with. */
    featured: MenuItem[];
    combos: Combo[];
    sections: Section[];
    /**
     * The order the blocks of this menu are read in: the two rails by name and
     * a section by its id.
     *
     * The tenant drags all three against each other in the panel, so where
     * the featured items and the combos sit is its decision rather than this
     * page's — which is why the order is decided on the server and sent, not
     * assembled here.
     */
    order: (number | 'featured' | 'combos')[];
    /** Every add-on group the page's items offer, sent once however many items share it. */
    addOnGroups: AddOnGroup[];
    tax: Tax;
    /** Only the charges this menu carries, switched on, in the tenant's order. */
    charges: Charge[];
    acceptingOrders: boolean;
    /** Where the basket is priced. */
    quoteUrl: string;
    homeUrl: string;
}

/**
 * What an Add button does, wherever the item or combo sits on the page.
 *
 * Null while nothing can be ordered — the tenant is closed, or this menu is not
 * being served right now — and then no Add button is drawn at all.
 */
interface Ordering {
    addItem: (item: MenuItem) => void;
    addCombo: (combo: Combo) => void;
    /** Whether the basket already holds as many of it as one order may. */
    isFull: (type: BasketLineType, thing: MenuItem | Combo) => boolean;
}

const OrderingContext = createContext<Ordering | null>(null);

/**
 * Turn basis points into the percentage a guest reads: 500 becomes "5%".
 *
 * The server sends basis points because that is how a rate is stored — an
 * integer, so the arithmetic behind a bill stays exact. Only the reader wants a
 * percentage, so the conversion belongs here, beside the money formatting and
 * for the same reason.
 */
function percentage(basisPoints: number): string {
    return `${String(Number((basisPoints / 100).toFixed(2)))}%`;
}

/**
 * One of a tenant's menus, read at the table or in the room.
 *
 * One narrow column, thumb-sized rows, no hover anywhere: a guest is holding a
 * phone in one hand. Only orderable items arrive here, so there is nothing
 * greyed out to scroll past — and the back arrow returns to the tiles they came
 * in through.
 *
 * While the tenant takes orders and the menu is being served, each item and
 * combo can be added to a basket kept on the phone. An item with add-on groups
 * opens a sheet to customise it first. The basket is priced by the server, and
 * is shown to a member of staff: nothing is ordered from here yet.
 *
 * Every name on this page is already in the guest's language: the server picked
 * the translation, falling back to English where a tenant has not filled
 * one in. Prices arrive as integers and are formatted here, so they follow that
 * same language — see resources/js/lib/money.ts.
 */
export default function Menu({
    tenant,
    menu,
    featured,
    combos,
    sections,
    order,
    addOnGroups,
    tax,
    charges,
    acceptingOrders,
    quoteUrl,
    homeUrl,
}: MenuProps) {
    const { t } = useTranslations();
    const basket = useBasket(basketStorageKey(tenant?.slug ?? '', menu.id));
    const [customising, setCustomising] = useState<MenuItem | null>(null);
    const [isBasketOpen, setBasketOpen] = useState(false);

    const isEmpty =
        sections.length === 0 && featured.length === 0 && combos.length === 0;

    const sectionsById = new Map(
        sections.map((section) => [section.id, section]),
    );

    const groupsById = new Map(addOnGroups.map((group) => [group.id, group]));

    const groupsOf = (item: MenuItem): AddOnGroup[] =>
        item.addOnGroupIds.flatMap((id) => {
            const group = groupsById.get(id);

            return group === undefined ? [] : [group];
        });

    const heldOf = (type: BasketLineType, id: number): number =>
        quantityHeld(basket.lines, type, id);

    const ordering: Ordering | null =
        acceptingOrders && menu.isBeingServed
            ? {
                  addItem: (item) => {
                      if (item.addOnGroupIds.length > 0) {
                          setCustomising(item);

                          return;
                      }

                      basket.add({
                          type: 'item',
                          id: item.id,
                          name: item.name,
                          choices: [],
                          quantity: 1,
                      });
                  },
                  addCombo: (combo) => {
                      basket.add({
                          type: 'combo',
                          id: combo.id,
                          name: combo.name,
                          choices: [],
                          quantity: 1,
                      });
                  },
                  isFull: (type, thing) =>
                      roomFor(thing, heldOf(type, thing.id)) === 0,
              }
            : null;

    return (
        <OrderingContext.Provider value={ordering}>
            <Head title={menu.name} />

            <AppBar title={menu.name} eyebrow={tenant?.name} backHref={homeUrl}>
                <Badge variant={acceptingOrders ? 'default' : 'secondary'}>
                    {acceptingOrders ? t('status.open') : t('status.closed')}
                </Badge>
            </AppBar>

            <main className="flex-1 pb-[max(2rem,env(safe-area-inset-bottom))]">
                {menu.description !== null && (
                    <p className="text-muted-foreground px-5 pt-4 text-sm">
                        {menu.description}
                    </p>
                )}

                {/* A menu served only at certain hours says so, and says it
                    louder once those hours have passed — a guest reading the
                    breakfast card at three should not have to work out why
                    nobody will bring them any. */}
                {menu.servedFrom !== null && menu.servedUntil !== null && (
                    <p
                        className={`px-5 pt-3 text-sm ${
                            menu.isBeingServed
                                ? 'text-muted-foreground'
                                : 'text-foreground font-medium'
                        }`}
                    >
                        {t('menu.served_between', {
                            from: menu.servedFrom,
                            until: menu.servedUntil,
                        })}
                        {!menu.isBeingServed &&
                            ` · ${t('menu.not_being_served')}`}
                    </p>
                )}

                {isEmpty ? (
                    <p className="text-muted-foreground px-5 py-16 text-center text-sm">
                        {t('menu.empty')}
                    </p>
                ) : (
                    /* In the order the tenant arranged, rails included: a
                       menu that leads with its combos and one that closes with
                       them are the same screen read in a different order. */
                    order.map((block) => {
                        if (block === 'featured') {
                            return featured.length > 0 ? (
                                <FeaturedRail key="featured" items={featured} />
                            ) : null;
                        }

                        if (block === 'combos') {
                            return combos.length > 0 ? (
                                <CombosRail key="combos" combos={combos} />
                            ) : null;
                        }

                        const section = sectionsById.get(block);

                        return section ? (
                            <SectionBlock key={section.id} section={section} />
                        ) : null;
                    })
                )}

                {!isEmpty && <ChargesNote tax={tax} charges={charges} />}
            </main>

            {basket.count > 0 && (
                <BasketBar
                    count={basket.count}
                    onOpen={() => {
                        setBasketOpen(true);
                    }}
                />
            )}

            <CustomiseSheet
                item={customising}
                groups={customising === null ? [] : groupsOf(customising)}
                held={customising === null ? 0 : heldOf('item', customising.id)}
                onClose={() => {
                    setCustomising(null);
                }}
                onAdd={(choices, quantity) => {
                    if (customising !== null) {
                        basket.add({
                            type: 'item',
                            id: customising.id,
                            name: customising.name,
                            choices,
                            quantity,
                        });
                    }

                    setCustomising(null);
                }}
            />

            <BasketSheet
                open={isBasketOpen}
                onOpenChange={setBasketOpen}
                basket={basket}
                quoteUrl={quoteUrl}
                describe={describeLine({
                    items: [
                        ...featured,
                        ...sections.flatMap((section) => [
                            ...section.items,
                            ...section.subSections.flatMap(
                                (subSection) => subSection.items,
                            ),
                        ]),
                    ],
                    combos,
                    addOnGroups,
                })}
            />
        </OrderingContext.Provider>
    );
}

/**
 * How a basket line reads, in the guest's language.
 *
 * The basket keeps ids, so its names come from this page's props — which
 * follow a language switch — and fall back to the name it was added under for
 * a line the menu no longer lists.
 */
function describeLine({
    items,
    combos,
    addOnGroups,
}: {
    items: MenuItem[];
    combos: Combo[];
    addOnGroups: AddOnGroup[];
}): (line: BasketLine) => LineDescription {
    const itemsById = new Map(items.map((item) => [item.id, item]));
    const combosById = new Map(combos.map((combo) => [combo.id, combo]));
    const optionsById = new Map(
        addOnGroups
            .flatMap((group) => group.options)
            .map((option) => [option.id, option]),
    );

    return (line) => {
        const item = line.type === 'item' ? itemsById.get(line.id) : undefined;
        const combo =
            line.type === 'combo' ? combosById.get(line.id) : undefined;

        return {
            name: item?.name ?? combo?.name ?? line.name,
            diet: item?.diet ?? null,
            isServiceRequest: item?.isServiceRequest ?? false,
            limits: item ?? combo ?? { maxQuantity: null },
            choices: line.choices.flatMap((choice) => {
                const option = optionsById.get(choice.optionId);

                if (option === undefined) {
                    return [];
                }

                return [
                    choice.quantity > 1
                        ? `${String(choice.quantity)} × ${option.name}`
                        : option.name,
                ];
            }),
        };
    };
}

/**
 * The items the menu leads with.
 *
 * A rail rather than a list: these are the items the tenant wants seen
 * first, and a guest should meet them before scrolling rather than instead of
 * the sections, where each one also appears.
 */
function FeaturedRail({ items }: { items: MenuItem[] }) {
    const { t } = useTranslations();

    return (
        <section className="pt-6">
            <h2 className="text-muted-foreground flex items-center gap-1.5 px-5 text-xs font-semibold tracking-widest uppercase">
                <StarIcon className="size-3.5" />
                {t('menu.featured')}
            </h2>

            <ul className="mt-2 flex snap-x snap-mandatory [scrollbar-width:none] gap-3 overflow-x-auto px-5 pb-1 [&::-webkit-scrollbar]:hidden">
                {items.map((item) => (
                    <li
                        key={item.id}
                        className="bg-card w-72 shrink-0 snap-start rounded-xl border"
                    >
                        <Item item={item} />
                    </li>
                ))}
            </ul>
        </section>
    );
}

/**
 * The bundles sold at one price.
 *
 * The same rail as the featured row: a combo is something the menu leads with,
 * not something in a section, so it is read the same way.
 */
function CombosRail({ combos }: { combos: Combo[] }) {
    const { t } = useTranslations();

    return (
        <section className="pt-6">
            <h2 className="text-muted-foreground px-5 text-xs font-semibold tracking-widest uppercase">
                {t('menu.combos')}
            </h2>

            <ul className="mt-2 flex snap-x snap-mandatory [scrollbar-width:none] gap-3 overflow-x-auto px-5 pb-1 [&::-webkit-scrollbar]:hidden">
                {combos.map((combo) => (
                    <li
                        key={combo.id}
                        className="bg-card w-72 shrink-0 snap-start rounded-xl border"
                    >
                        <ComboCard combo={combo} />
                    </li>
                ))}
            </ul>
        </section>
    );
}

/**
 * One section of the menu: its own items, then its subdivisions.
 *
 * Headings are nested for real — the section is an h2, a subdivision an h3, and
 * an item inside one an h4 — so someone navigating by headings is reading the
 * menu's actual structure.
 */
function SectionBlock({ section }: { section: Section }) {
    return (
        <section className="pt-6">
            <h2 className="text-muted-foreground px-5 text-xs font-semibold tracking-widest uppercase">
                {section.name}
            </h2>

            {section.items.length > 0 && (
                <ul className="mt-2">
                    {section.items.map((item, index) => (
                        <li key={item.id}>
                            {index > 0 && (
                                <Separator className="mx-5 data-[orientation=horizontal]:w-auto" />
                            )}
                            <Item item={item} />
                        </li>
                    ))}
                </ul>
            )}

            {/* A subdivision is a quieter heading than its category, indented
                rather than shouted, so the nesting is read at a glance without
                a second level of uppercase competing with the first. */}
            {section.subSections.map((subSection) => (
                <div key={subSection.id} className="mt-4">
                    <h3 className="text-foreground/80 px-5 text-sm font-semibold">
                        {subSection.name}
                    </h3>

                    <ul className="mt-1">
                        {subSection.items.map((item, index) => (
                            <li key={item.id}>
                                {index > 0 && (
                                    <Separator className="mx-5 data-[orientation=horizontal]:w-auto" />
                                )}
                                <Item item={item} headingLevel={4} />
                            </li>
                        ))}
                    </ul>
                </div>
            ))}
        </section>
    );
}

/**
 * What a price does and does not include, and what a bill has added to it.
 *
 * The small print at the bottom of a menu. It is the one place a guest is told
 * what the bill will add before they order rather than after, so it is plain
 * text rather than something to be tapped open. Only the charges this menu
 * carries arrive here: a card of housekeeping requests may carry none.
 */
function ChargesNote({ tax, charges }: { tax: Tax; charges: Charge[] }) {
    const { t } = useTranslations();
    const money = useMoney();

    const rate = percentage(tax.rateBasisPoints);

    return (
        <footer className="text-muted-foreground mt-8 space-y-1 px-5 text-xs leading-relaxed">
            <p>
                {tax.pricesIncludeTax
                    ? t('menu.tax_included', { rate })
                    : t('menu.tax_excluded', { rate })}
            </p>

            {charges.map((charge) => (
                <p key={charge.id}>
                    {charge.rateBasisPoints !== null
                        ? t('menu.charge_rate', {
                              name: charge.name,
                              rate: percentage(charge.rateBasisPoints),
                          })
                        : t('menu.charge_amount', {
                              name: charge.name,
                              amount: money(charge.amountMinorUnits ?? 0),
                          })}
                </p>
            ))}
        </footer>
    );
}

/**
 * A bundle and what a guest gets in it.
 */
function ComboCard({ combo }: { combo: Combo }) {
    const { t } = useTranslations();
    const ordering = useContext(OrderingContext);

    return (
        <article className="px-5 py-4">
            <h3 className="leading-snug font-medium">{combo.name}</h3>

            {combo.description !== null && (
                <p className="text-muted-foreground mt-1 text-sm leading-snug">
                    {combo.description}
                </p>
            )}

            {combo.contents.length > 0 && (
                <div className="mt-2">
                    <p className="text-muted-foreground text-xs font-medium">
                        {t('menu.combo_contains')}
                    </p>

                    <ul className="mt-1 space-y-0.5">
                        {combo.contents.map((content) => (
                            <li
                                key={content.id}
                                className="text-muted-foreground flex items-center gap-2 text-sm"
                            >
                                <ItemMark
                                    diet={content.diet}
                                    isServiceRequest={content.isServiceRequest}
                                />
                                <span>
                                    {content.quantity > 1 &&
                                        `${String(content.quantity)} × `}
                                    {content.name}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <div className="mt-3 flex items-center justify-between gap-3">
                <Price
                    priceMinorUnits={combo.priceMinorUnits}
                    compareAtPriceMinorUnits={combo.compareAtPriceMinorUnits}
                />

                {ordering !== null && (
                    <AddControl
                        name={combo.name}
                        isFull={ordering.isFull('combo', combo)}
                        onAdd={() => {
                            ordering.addCombo(combo);
                        }}
                    />
                )}
            </div>
        </article>
    );
}

/**
 * A price, with what it used to be struck through beside it.
 *
 * The old price is deliberately smaller and quieter than the real one: it is
 * context for the number a guest is being asked to pay, not a second number
 * competing with it. The server only ever sends a compare-at price that is higher
 * than what is charged, so there is no case here for one that is not.
 *
 * Zero is a real price — an extra pillow, a glass of water — and "₹0.00"
 * reads as a mistake, so it is named instead, with nothing struck through, and
 * quietly: on a card of room requests nearly every row is complimentary, and
 * the word in bold on each one outshouted the names.
 */
function Price({
    priceMinorUnits,
    compareAtPriceMinorUnits,
    className = '',
}: {
    priceMinorUnits: number;
    compareAtPriceMinorUnits: number | null;
    className?: string;
}) {
    const { t } = useTranslations();
    const money = useMoney();

    if (priceMinorUnits === 0) {
        return (
            <p
                className={`text-muted-foreground text-sm font-medium ${className}`}
            >
                {t('menu.complimentary')}
            </p>
        );
    }

    return (
        <p className={`flex items-baseline gap-1.5 tabular-nums ${className}`}>
            {compareAtPriceMinorUnits !== null && (
                <span className="text-muted-foreground text-sm line-through">
                    {money(compareAtPriceMinorUnits)}
                </span>
            )}
            <span className="text-primary font-semibold">
                {money(priceMinorUnits)}
            </span>
        </p>
    );
}

/**
 * Put something in the basket, named for screen readers: "Add Paneer Tikka".
 *
 * Once the basket holds as many as one order may, the button stays where it
 * is, greyed, with "Limit reached" under it. Hidden, it would read as sold out.
 */
function AddControl({
    name,
    isFull,
    isCustomisable = false,
    onAdd,
}: {
    name: string;
    isFull: boolean;
    isCustomisable?: boolean;
    onAdd: () => void;
}) {
    const { t } = useTranslations();

    const caption = isFull
        ? t('limits.reached')
        : isCustomisable
          ? t('menu.customisable')
          : null;

    return (
        <div className="flex shrink-0 flex-col items-center gap-1">
            <Button
                type="button"
                variant="outline"
                size="sm"
                className="text-primary min-w-16 font-semibold"
                aria-label={t('menu.add_named', { name })}
                disabled={isFull}
                onClick={onAdd}
            >
                <PlusIcon />
                {t('menu.add')}
            </Button>

            {caption !== null && (
                <span className="text-muted-foreground text-xs">{caption}</span>
            )}
        </div>
    );
}

/**
 * One item, and a way to add it while the menu can be ordered from.
 *
 * The heading level is a prop because the same item is rendered at two depths:
 * straight under a category it is an h3, and inside one of that category's
 * subdivisions — which is itself an h3 — it is an h4. Hard-coding one would
 * either put two different things at the same level or skip one, and a guest
 * reading the menu with a screen reader navigates by exactly this structure.
 *
 * An item with add-on groups says it can be customised under its Add button,
 * which opens the sheet rather than adding it straight away.
 */
function Item({
    item,
    headingLevel = 3,
}: {
    item: MenuItem;
    headingLevel?: 3 | 4;
}) {
    const ordering = useContext(OrderingContext);
    const Heading = headingLevel === 4 ? 'h4' : 'h3';

    return (
        <article className="flex items-start gap-3 px-5 py-4">
            <ItemMark
                diet={item.diet}
                isServiceRequest={item.isServiceRequest}
                className="mt-1"
            />

            {/* Name, price and description read down the left, the way a
                guest already reads a delivery app, which leaves the right to
                Add alone. With the price stacked under Add as well, a row with
                no description was four lines tall beside a one-line name. */}
            <div className="min-w-0 flex-1">
                <Heading className="leading-snug font-medium">
                    {item.name}
                </Heading>

                <Price
                    priceMinorUnits={item.priceMinorUnits}
                    compareAtPriceMinorUnits={item.compareAtPriceMinorUnits}
                    className="mt-0.5"
                />

                {item.description !== null && (
                    <p className="text-muted-foreground mt-1 text-sm leading-snug">
                        {item.description}
                    </p>
                )}
            </div>

            {ordering !== null && (
                <AddControl
                    name={item.name}
                    isFull={ordering.isFull('item', item)}
                    isCustomisable={item.addOnGroupIds.length > 0}
                    onAdd={() => {
                        ordering.addItem(item);
                    }}
                />
            )}
        </article>
    );
}
