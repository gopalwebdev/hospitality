import { Head, Link } from '@inertiajs/react';

import { AppBar } from '@/components/app-bar';
import { useTranslations } from '@/hooks/use-translations';
import { cn } from '@/lib/utils';

interface Tile {
    id: number;
    label: string;
    imageUrl: string | null;
    href: string;
    /** A link tile leaves the app, so it is an anchor rather than a visit. */
    isExternal: boolean;
}

interface Row {
    id: number;
    title: string | null;
    layout: string;
    /** The CSS aspect ratio this row's layout is drawn at, decided server-side. */
    aspectRatio: string;
    /** Whether the tiles sit on a rail the guest swipes rather than stacking. */
    isScrollable: boolean;
    /** Whether a tile in this row is drawn as a circle. */
    isCircular: boolean;
    tiles: Tile[];
}

interface HomeProps {
    tenant: { name: string; slug: string; typeNoun: string } | null;
    rows: Row[];
}

/**
 * What a guest sees after scanning the QR code at their table.
 *
 * The tenant arranged this — the rows, what each one looks like, the
 * pictures and where each tile goes — so this page draws whatever it is given
 * and decides nothing. The row carries its own shape down from the server, so
 * adding a layout is a case in App\Enums\HomeRowLayout and a branch here,
 * never a guess about what a row is for.
 */
export default function Home({ tenant, rows }: HomeProps) {
    const { t } = useTranslations();
    const title = tenant?.name ?? t('home.title');

    return (
        <>
            <Head title={title} />

            <AppBar title={title} />

            <main className="flex-1 pt-4 pb-[max(2rem,env(safe-area-inset-bottom))]">
                {rows.length === 0 ? (
                    <p className="text-muted-foreground px-5 py-16 text-center text-sm">
                        {t('home.empty', { type: tenant?.typeNoun ?? '' })}
                    </p>
                ) : (
                    <div className="flex flex-col gap-6">
                        {rows.map((row) => (
                            <HomeRow key={row.id} row={row} />
                        ))}
                    </div>
                )}
            </main>
        </>
    );
}

/**
 * One band of the home screen.
 *
 * A scrolling row bleeds to both edges so the tile at the end is visibly cut
 * off — that is what tells a thumb there is more to swipe to — while a stacked
 * row keeps the page's own margin.
 */
function HomeRow({ row }: { row: Row }) {
    return (
        <section aria-label={row.title ?? undefined}>
            {row.title !== null && (
                <h2 className="px-5 pb-2 text-base font-semibold tracking-tight">
                    {row.title}
                </h2>
            )}

            {row.isScrollable ? (
                <ul className="flex snap-x snap-mandatory [scrollbar-width:none] gap-3 overflow-x-auto px-5 pb-1 [&::-webkit-scrollbar]:hidden">
                    {row.tiles.map((tile) => (
                        <li
                            key={tile.id}
                            className={cn(
                                'shrink-0 snap-start',
                                row.isCircular ? 'w-20' : 'w-64',
                            )}
                        >
                            <TileLink tile={tile} row={row} />
                        </li>
                    ))}
                </ul>
            ) : (
                <ul className="flex flex-col gap-3 px-5">
                    {row.tiles.map((tile) => (
                        <li key={tile.id}>
                            <TileLink tile={tile} row={row} />
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

/**
 * A tile and the tap target around it.
 *
 * An external link is a plain anchor: Inertia would otherwise try to fetch
 * Instagram as a page of this app and fail.
 *
 * Any other tile prefetches its page the moment a finger lands on it, or on
 * hover where there is a pointer, so the menu is usually already loaded by the
 * time the tap completes.
 */
function TileLink({ tile, row }: { tile: Tile; row: Row }) {
    const className =
        'focus-visible:ring-ring block rounded-xl focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';

    const inner = <Tile tile={tile} row={row} />;

    if (tile.isExternal) {
        return (
            <a
                href={tile.href}
                target="_blank"
                rel="noreferrer noopener"
                className={className}
            >
                {inner}
            </a>
        );
    }

    return (
        <Link
            href={tile.href}
            className={className}
            prefetch={['hover', 'click']}
        >
            {inner}
        </Link>
    );
}

/**
 * One tile.
 *
 * A tile with no picture is not broken — it is a tenant that has arranged
 * its home screen before it has photography — so it is drawn as its label on
 * the brand colour rather than as an empty box.
 *
 * A circular tile puts its label underneath rather than over the picture:
 * there is no room for text inside eighty pixels of circle.
 */
function Tile({ tile, row }: { tile: Tile; row: Row }) {
    const frame = cn(
        'relative overflow-hidden',
        row.isCircular ? 'rounded-full' : 'rounded-xl',
    );

    const picture =
        tile.imageUrl === null ? (
            <div
                className={cn(
                    frame,
                    'bg-primary text-primary-foreground flex items-center justify-center px-3',
                )}
                style={{ aspectRatio: row.aspectRatio }}
            >
                {!row.isCircular && (
                    <span className="text-center text-lg font-semibold">
                        {tile.label}
                    </span>
                )}
            </div>
        ) : (
            <div className={frame} style={{ aspectRatio: row.aspectRatio }}>
                <img
                    src={tile.imageUrl}
                    alt={tile.label}
                    className="size-full object-cover"
                    loading="lazy"
                    decoding="async"
                />

                {/* On a rectangle the label sits over the picture, so the tile
                    stays one tap target and the name is readable whatever the
                    photograph is. A circle has nowhere to put it. */}
                {!row.isCircular && (
                    <div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent px-4 pt-8 pb-3">
                        <span className="text-lg font-semibold text-white drop-shadow">
                            {tile.label}
                        </span>
                    </div>
                )}
            </div>
        );

    if (!row.isCircular) {
        return picture;
    }

    return (
        <div className="flex flex-col items-center gap-1.5">
            {picture}
            <span className="line-clamp-2 text-center text-xs leading-tight font-medium">
                {tile.label}
            </span>
        </div>
    );
}
