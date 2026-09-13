import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vite-plus/test';

import Home from '@/pages/guest/home';

const tenant = { name: 'Spice Garden', slug: 'spice' };

type HomeProps = Parameters<typeof Home>[0];
type RowProp = HomeProps['rows'][number];
type TileProp = RowProp['tiles'][number];

function tile(overrides: Partial<TileProp> = {}): TileProp {
    return {
        id: 1,
        label: 'Menu',
        imageUrl: 'http://spice.tenant-app.test/tiles/1/image',
        href: 'http://spice.tenant-app.test/menus/1',
        isExternal: false,
        ...overrides,
    };
}

/** A banner row, which is what a tenant leads with. */
function row(overrides: Partial<RowProp> = {}): RowProp {
    return {
        id: 1,
        title: null,
        layout: 'banner',
        aspectRatio: '16 / 9',
        isScrollable: false,
        isCircular: false,
        tiles: [tile()],
        ...overrides,
    };
}

describe('guest home', () => {
    it('names the tenant at the top', () => {
        render(<Home tenant={tenant} rows={[row()]} />);

        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(
            'Spice Garden',
        );
    });

    it('draws the tiles in the order the tenant arranged them', () => {
        render(
            <Home
                tenant={tenant}
                rows={[
                    row({
                        tiles: [
                            tile({ id: 1, label: 'Menu' }),
                            tile({ id: 2, label: 'Drinks' }),
                            tile({ id: 3, label: 'Offers' }),
                        ],
                    }),
                ]}
            />,
        );

        // The server has already sorted them; the page must not re-sort.
        const links = screen.getAllByRole('link');

        expect(links).toHaveLength(3);
        expect(links[0]).toHaveTextContent('Menu');
        expect(links[1]).toHaveTextContent('Drinks');
        expect(links[2]).toHaveTextContent('Offers');
    });

    it('draws the rows in the order the tenant arranged them', () => {
        render(
            <Home
                tenant={tenant}
                rows={[
                    row({ id: 1, title: 'Eat', tiles: [tile({ id: 1 })] }),
                    row({ id: 2, title: 'Offers', tiles: [tile({ id: 2 })] }),
                ]}
            />,
        );

        const headings = screen.getAllByRole('heading', { level: 2 });

        expect(headings).toHaveLength(2);
        expect(headings[0]).toHaveTextContent('Eat');
        expect(headings[1]).toHaveTextContent('Offers');
    });

    it('draws no heading over a row that has none', () => {
        render(<Home tenant={tenant} rows={[row({ title: null })]} />);

        expect(
            screen.queryByRole('heading', { level: 2 }),
        ).not.toBeInTheDocument();
    });

    it('sends each tile where the server said it goes', () => {
        render(
            <Home
                tenant={tenant}
                rows={[
                    row({
                        tiles: [
                            tile({
                                id: 7,
                                label: 'Wine list',
                                href: 'http://spice.tenant-app.test/tiles/7',
                            }),
                        ],
                    }),
                ]}
            />,
        );

        expect(screen.getByRole('link')).toHaveAttribute(
            'href',
            expect.stringContaining('/tiles/7'),
        );
    });

    it('leaves the app through a plain anchor for a link tile', () => {
        render(
            <Home
                tenant={tenant}
                rows={[
                    row({
                        layout: 'links',
                        isCircular: true,
                        isScrollable: true,
                        aspectRatio: '1 / 1',
                        tiles: [
                            tile({
                                label: 'Instagram',
                                href: 'https://instagram.com/spice',
                                isExternal: true,
                            }),
                        ],
                    }),
                ]}
            />,
        );

        // Inertia would otherwise try to fetch Instagram as a page of this app.
        const link = screen.getByRole('link');

        expect(link).toHaveAttribute('href', 'https://instagram.com/spice');
        expect(link).toHaveAttribute('target', '_blank');
    });

    it('labels a circular tile underneath, where there is room for it', () => {
        render(
            <Home
                tenant={tenant}
                rows={[
                    row({
                        layout: 'links',
                        isCircular: true,
                        isScrollable: true,
                        aspectRatio: '1 / 1',
                        tiles: [tile({ label: 'WhatsApp' })],
                    }),
                ]}
            />,
        );

        expect(screen.getByText('WhatsApp')).toBeInTheDocument();
    });

    it("labels a tile's picture for a screen reader", () => {
        render(
            <Home
                tenant={tenant}
                rows={[row({ tiles: [tile({ label: 'Menu' })] })]}
            />,
        );

        expect(screen.getByRole('img', { name: 'Menu' })).toBeInTheDocument();
    });

    it('draws a tile with no picture as its label rather than an empty box', () => {
        render(
            <Home
                tenant={tenant}
                rows={[
                    row({ tiles: [tile({ label: 'Drinks', imageUrl: null })] }),
                ]}
            />,
        );

        expect(screen.queryByRole('img')).not.toBeInTheDocument();
        expect(screen.getByText('Drinks')).toBeInTheDocument();
    });

    it('says so plainly when the tenant has arranged nothing yet', () => {
        render(<Home tenant={tenant} rows={[]} />);

        expect(screen.getByText(/no home screen yet/i)).toBeInTheDocument();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });
});
