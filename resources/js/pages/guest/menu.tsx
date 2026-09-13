import { Head } from '@inertiajs/react';

import { AppBar } from '@/components/app-bar';
import { FoodTypeDot, type FoodType } from '@/components/food-type-dot';
import { StarIcon } from '@/components/icons';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { useMoney } from '@/hooks/use-money';
import { useTranslations } from '@/hooks/use-translations';

export interface Addition {
    id: number;
    name: string;
    /** An integer count of the currency's minor unit; 0 means free. */
    priceMinorUnits: number;
}

export interface MenuItem {
    id: number;
    name: string;
    description: string | null;
    priceMinorUnits: number;
    /** A higher price to show struck through, or null when not on offer. */
    compareAtPriceMinorUnits: number | null;
    foodType: FoodType;
    additions: Addition[];
}

interface ComboContent {
    id: number;
    name: string;
    foodType: FoodType;
    quantity: number;
}

export interface Combo {
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
    /** Dishes filed straight under the category, above its subdivisions. */
    items: MenuItem[];
    subSections: SubSection[];
}

export interface Charges {
    /** Basis points: 500 is 5%. */
    taxRateBasisPoints: number;
    pricesIncludeTax: boolean;
    /** Null when the tenant does not levy one. */
    serviceChargeBasisPoints: number | null;
    parcelChargeMinorUnits: number | null;
}

interface MenuProps {
    tenant: { name: string; slug: string; typeNoun: string } | null;
    menu: {
        id: number;
        name: string;
        description: string | null;
        servedFrom: string | null;
        servedUntil: string | null;
        isBeingServed: boolean;
    };
    /** The dishes this menu leads with. */
    featured: MenuItem[];
    combos: Combo[];
    sections: Section[];
    /**
     * The order the blocks of this menu are read in: the two rails by name and
     * a section by its id.
     *
     * The tenant drags all three against each other in the panel, so where
     * the featured dishes and the combos sit is its decision rather than this
     * page's — which is why the order is decided on the server and sent, not
     * assembled here.
     */
    order: (number | 'featured' | 'combos')[];
    charges: Charges;
    acceptingOrders: boolean;
    homeUrl: string;
}

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
 * One of a tenant's menus, read at the table.
 *
 * One narrow column, thumb-sized rows, no hover anywhere: a guest is holding a
 * phone in one hand. Only orderable dishes arrive here, so there is nothing
 * greyed out to scroll past — and the back arrow returns to the tiles they came
 * in through.
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
    charges,
    acceptingOrders,
    homeUrl,
}: MenuProps) {
    const { t } = useTranslations();

    const isEmpty =
        sections.length === 0 && featured.length === 0 && combos.length === 0;

    const sectionsById = new Map(
        sections.map((section) => [section.id, section]),
    );

    return (
        <>
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

                {!isEmpty && <ChargesNote charges={charges} />}
            </main>
        </>
    );
}

/**
 * The dishes the menu leads with.
 *
 * A rail rather than a list: these are the dishes the tenant wants seen
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
                        className="bg-card w-64 shrink-0 snap-start rounded-xl border"
                    >
                        <Dish item={item} />
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
 * One section of the menu: its own dishes, then its subdivisions.
 *
 * Headings are nested for real — the section is an h2, a subdivision an h3, and
 * a dish inside one an h4 — so someone navigating by headings is reading the
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
                            {index > 0 && <Separator className="ml-5" />}
                            <Dish item={item} />
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
                                {index > 0 && <Separator className="ml-5" />}
                                <Dish item={item} headingLevel={4} />
                            </li>
                        ))}
                    </ul>
                </div>
            ))}
        </section>
    );
}

/**
 * What a price does and does not include.
 *
 * The small print at the bottom of a menu. It is the one place a guest is told
 * what the bill will add before they order rather than after, so it is plain
 * text rather than something to be tapped open.
 */
function ChargesNote({ charges }: { charges: Charges }) {
    const { t } = useTranslations();
    const money = useMoney();

    const rate = percentage(charges.taxRateBasisPoints);

    const lines = [
        charges.pricesIncludeTax
            ? t('menu.tax_included', { rate })
            : t('menu.tax_excluded', { rate }),
        charges.serviceChargeBasisPoints !== null &&
            t('menu.service_charge', {
                rate: percentage(charges.serviceChargeBasisPoints),
            }),
        charges.parcelChargeMinorUnits !== null &&
            t('menu.parcel_charge', {
                amount: money(charges.parcelChargeMinorUnits),
            }),
    ].filter((line): line is string => typeof line === 'string');

    return (
        <footer className="text-muted-foreground mt-8 space-y-1 px-5 text-xs leading-relaxed">
            {lines.map((line) => (
                <p key={line}>{line}</p>
            ))}
        </footer>
    );
}

/**
 * A bundle and what a guest gets in it.
 */
function ComboCard({ combo }: { combo: Combo }) {
    const { t } = useTranslations();

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
                                <FoodTypeDot type={content.foodType} />
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

            <Price
                priceMinorUnits={combo.priceMinorUnits}
                compareAtPriceMinorUnits={combo.compareAtPriceMinorUnits}
                className="mt-3"
            />
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
    const money = useMoney();

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
 * One dish and the extras it can be ordered with.
 *
 * The heading level is a prop because the same dish is rendered at two depths:
 * straight under a category it is an h3, and inside one of that category's
 * subdivisions — which is itself an h3 — it is an h4. Hard-coding one would
 * either put two different things at the same level or skip one, and a guest
 * reading the menu with a screen reader navigates by exactly this structure.
 */
function Dish({
    item,
    headingLevel = 3,
}: {
    item: MenuItem;
    headingLevel?: 3 | 4;
}) {
    const { t } = useTranslations();
    const money = useMoney();
    const Heading = headingLevel === 4 ? 'h4' : 'h3';

    return (
        <article className="flex items-start gap-3 px-5 py-4">
            <FoodTypeDot type={item.foodType} className="mt-1" />

            <div className="min-w-0 flex-1">
                <Heading className="leading-snug font-medium">
                    {item.name}
                </Heading>

                {item.description !== null && (
                    <p className="text-muted-foreground mt-1 text-sm leading-snug">
                        {item.description}
                    </p>
                )}

                {item.additions.length > 0 && (
                    <div className="mt-2">
                        <p className="text-muted-foreground text-xs font-medium">
                            {t('menu.additions')}
                        </p>

                        <ul className="mt-1 flex flex-wrap gap-x-3 gap-y-1">
                            {item.additions.map((addition) => (
                                <li
                                    key={addition.id}
                                    className="text-muted-foreground text-sm"
                                >
                                    {addition.name}{' '}
                                    <span className="text-foreground/70 tabular-nums">
                                        {addition.priceMinorUnits === 0
                                            ? t('menu.free')
                                            : `+ ${money(addition.priceMinorUnits)}`}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>

            {/* Stacked rather than side by side: a struck-through price beside
                the real one on a phone pushes a long dish name into a third
                line, and the price column is the narrowest thing here. */}
            <Price
                priceMinorUnits={item.priceMinorUnits}
                compareAtPriceMinorUnits={item.compareAtPriceMinorUnits}
                className="shrink-0 flex-col items-end gap-0"
            />
        </article>
    );
}
