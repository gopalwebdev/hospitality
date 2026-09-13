<?php

namespace App\Http\Controllers\Guest;

use App\Enums\HomeTileAction;
use App\Http\Controllers\Controller;
use App\Models\HomeRow;
use App\Models\HomeTile;
use App\Models\Menu;
use App\Models\Tenant;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The screen a guest lands on after scanning the QR code at their table.
 *
 * What is on it is the tenant's arrangement, not ours: it chooses the rows,
 * what each one looks like, the tiles inside them and where each one goes. This
 * reads that arrangement and hands it over; the guest app draws whatever it is
 * given, one renderer per layout.
 *
 * Rows and their tiles come down together — one screen a guest scrolls — and a
 * row whose tiles all lead nowhere is dropped rather than drawn as an empty
 * band.
 */
class HomeController extends Controller
{
    public function __invoke(Tenant $tenant): Response
    {
        abort_unless($tenant->is_active, 404);

        $rows = HomeRow::query()
            ->select(['id', 'title', 'layout'])
            ->where('tenant_id', $tenant->getKey())
            ->active()
            // A menu tile pointing at a hidden menu would open an empty screen,
            // so the menu comes along and the ones that lead nowhere are
            // dropped below rather than shown and then apologised for.
            ->with(['tiles' => fn ($tiles) => $tiles
                ->select(['id', 'home_row_id', 'label', 'image_path', 'action', 'menu_id', 'url'])
                ->active()
                ->with('menu:id,is_active')
                ->inDisplayOrder()])
            ->inDisplayOrder()
            ->get();

        return Inertia::render('home', [
            'rows' => $rows
                ->map(fn (HomeRow $row): array => $this->presentRow($row, $tenant))
                ->filter(fn (array $row): bool => $row['tiles'] !== [])
                ->values()
                ->all(),
        ]);
    }

    /**
     * One row, and the tiles a guest can actually get somewhere from.
     *
     * @return array<string, mixed>
     */
    private function presentRow(HomeRow $row, Tenant $tenant): array
    {
        return [
            'id' => $row->getKey(),
            // A row with no heading and a row whose heading is an empty string
            // are the same row to a guest, and Spatie hands back the latter for
            // a null column — so both leave here as null and the app draws no
            // heading at all rather than an empty one.
            'title' => filled($row->title) ? $row->title : null,
            'layout' => $row->layout->value,
            // Sent rather than assumed in React, so the layout stored is the
            // layout rendered and there is no second list to keep in step.
            'aspectRatio' => $row->layout->aspectRatio(),
            'isScrollable' => $row->layout->isScrollable(),
            'isCircular' => $row->layout->isCircular(),
            'tiles' => $row->tiles
                ->filter(fn (HomeTile $tile): bool => $this->leadsSomewhere($tile))
                ->map(fn (HomeTile $tile): array => [
                    'id' => $tile->getKey(),
                    'label' => $tile->label,
                    'imageUrl' => $tile->hasImage()
                        ? route('guest.tiles.image.show', ['tenant' => $tenant->slug, 'tile' => $tile->getKey()])
                        : null,
                    'href' => $this->destinationOf($tile, $tenant),
                    // A link leaves the app, so the browser is told to treat it
                    // as one rather than as another screen of this one.
                    'isExternal' => $tile->action === HomeTileAction::Link,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Whether tapping this tile would actually arrive anywhere.
     *
     * A menu tile whose menu has been hidden is the case that matters: the
     * menu row still exists, so the foreign key is satisfied and nothing is
     * broken, but a guest tapping it would be shown a menu the tenant has
     * deliberately taken down.
     */
    private function leadsSomewhere(HomeTile $tile): bool
    {
        if ($tile->action !== HomeTileAction::Menu) {
            return true;
        }

        // menu_id is nullable because a PDF or link tile has none, so a menu
        // tile leads somewhere only when its menu is both there and showing.
        $menu = $tile->menu;

        return $menu instanceof Menu && $menu->is_active;
    }

    /**
     * Where this tile takes the guest.
     *
     * Built here rather than on the model because every URL on a tenant domain
     * needs the tenant's slug, and reaching for it through the tile would
     * be a lazy load per row.
     */
    private function destinationOf(HomeTile $tile, Tenant $tenant): string
    {
        return match ($tile->action) {
            HomeTileAction::Menu => route('guest.menus.show', [
                'tenant' => $tenant->slug,
                'menu' => $tile->menu_id,
            ]),
            HomeTileAction::Pdf => route('guest.tiles.show', [
                'tenant' => $tenant->slug,
                'tile' => $tile->getKey(),
            ]),
            HomeTileAction::Link => (string) $tile->url,
        };
    }
}
