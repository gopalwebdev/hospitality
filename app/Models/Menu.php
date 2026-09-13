<?php

namespace App\Models;

use App\Enums\MenuBlock;
use App\Models\Concerns\HasTranslatedNames;
use Carbon\CarbonImmutable;
use Database\Factories\MenuFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * One of a tenant's menus: Lunch, Dinner, Drinks.
 *
 * The top of the hierarchy a guest reads down: a menu lists categories, a
 * category lists dishes, and a dish may offer additions. A tenant that
 * serves one card all day simply has one menu.
 *
 * The name and description are translated columns — one value per
 * App\Enums\Locale case. See App\Models\Concerns\HasTranslatedNames.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $description
 * @property int $position
 * @property int $featured_position
 * @property int $combos_position
 * @property bool $is_active
 * @property string|null $available_from
 * @property string|null $available_until
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'description', 'position', 'featured_position', 'combos_position', 'is_active', 'available_from', 'available_until'])]
class Menu extends Model
{
    /** @use HasFactory<MenuFactory> */
    use HasFactory;

    use HasTranslatedNames;

    /**
     * The columns holding one value per language.
     *
     * @var list<string>
     */
    public array $translatable = ['name', 'description'];

    /**
     * A new menu is showing until it is told otherwise, so an unsaved one reads
     * correctly under Model::shouldBeStrict().
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'position' => 0,
        // Both start where the first category does; the tie is broken rails
        // first, so a menu nobody has arranged opens with its featured dishes,
        // then its combos, then its categories. See readingOrder().
        'featured_position' => 0,
        'combos_position' => 0,
        'is_active' => true,
    ];

    /**
     * The tenant this menu belongs to.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Every category on this menu, at both levels.
     *
     * Rarely what a caller wants on its own — categories() and subCategories()
     * below are the two halves anything actually renders.
     *
     * @return HasMany<MenuCategory, $this>
     */
    public function menuCategories(): HasMany
    {
        return $this->hasMany(MenuCategory::class);
    }

    /**
     * The sections of this menu: the categories a guest reads down.
     *
     * @return HasMany<MenuCategory, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(MenuCategory::class)->whereNull('parent_id');
    }

    /**
     * Every subdivision on this menu, whichever category it sits under.
     *
     * @return HasMany<MenuCategory, $this>
     */
    public function subCategories(): HasMany
    {
        return $this->hasMany(MenuCategory::class)->whereNotNull('parent_id');
    }

    /**
     * The bundles offered on this menu.
     *
     * A combo hangs off the menu directly rather than off a category — it is
     * something the menu leads with, not something in a section. See MenuCombo.
     *
     * @return HasMany<MenuCombo, $this>
     */
    public function combos(): HasMany
    {
        return $this->hasMany(MenuCombo::class);
    }

    /**
     * The tiles on the home screen that open this menu.
     *
     * @return HasMany<HomeTile, $this>
     */
    public function homeTiles(): HasMany
    {
        return $this->hasMany(HomeTile::class);
    }

    /**
     * Every dish on this menu, whichever section it sits in.
     *
     * Reached through the sections because that is the only path there is —
     * a dish carries its section and its tenant, never its menu. The
     * featured row on the guest's menu screen and the panel's own reordering
     * of it both read this.
     *
     * @return HasManyThrough<MenuItem, MenuCategory, $this>
     */
    public function menuItems(): HasManyThrough
    {
        return $this->hasManyThrough(MenuItem::class, MenuCategory::class);
    }

    /**
     * The blocks of this menu in the order they are read, rails included.
     *
     * A menu is a sequence of three kinds of thing — the featured rail, the
     * combos rail and the categories — and all three are ordered against each
     * other by `position`. The rails keep theirs on this row
     * (App\Enums\MenuBlock::positionColumn()), a category on its own.
     *
     * Ties break rails first and then in declaration order, which is what makes
     * a menu nobody has arranged yet read exactly as it did before those
     * columns existed: the featured dishes, the combos, then the categories.
     * Once dragged, every block on the menu is renumbered uniquely and nothing
     * ties.
     *
     * The categories are taken in the order given rather than re-sorted, so a
     * caller that has already asked the database for `inMenuOrder()` keeps it.
     *
     * @param  iterable<MenuCategory>  $categories  this menu's top-level categories, in order
     * @return list<MenuBlock|MenuCategory>
     */
    public function readingOrder(iterable $categories): array
    {
        $blocks = [];

        foreach (MenuBlock::cases() as $rail) {
            $blocks[] = [$rail->positionOn($this), $rail === MenuBlock::Featured ? 0 : 1, $rail];
        }

        foreach ($categories as $category) {
            $blocks[] = [$category->position, 2, $category];
        }

        // PHP sorts stably, so categories sharing a position keep the order the
        // query returned them in.
        usort($blocks, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(static fn (array $block): MenuBlock|MenuCategory => $block[2], $blocks);
    }

    /**
     * Whether this menu is only served between certain hours.
     *
     * The pair is all-or-nothing, so asking about one answers for both. A menu
     * with no window is served whenever the tenant is open, which is what
     * most menus do.
     */
    public function hasServiceWindow(): bool
    {
        return filled($this->available_from) && filled($this->available_until);
    }

    /**
     * Whether this menu is being served at a given moment.
     *
     * A menu with no window is always being served. One whose window runs
     * backwards — 22:00 to 02:00, for a late card — wraps past midnight rather
     * than being empty, which is why this is a comparison of two cases and not
     * a single between().
     *
     * The zone is the application's, from APP_TIMEZONE: a tenant has no
     * timezone of its own here, deliberately (.ai/rules/app.md).
     */
    public function isBeingServedAt(?CarbonImmutable $moment = null): bool
    {
        if (! $this->hasServiceWindow()) {
            return true;
        }

        $from = $this->available_from;
        $until = $this->available_until;

        // hasServiceWindow() has already established both are filled; reading
        // them into locals is what makes that visible here.
        if ($from === null || $until === null) {
            return true;
        }

        $now = ($moment ?? CarbonImmutable::now())->format('H:i:s');
        $from = $this->normalisedTime($from);
        $until = $this->normalisedTime($until);

        return $from <= $until
            ? $now >= $from && $now < $until
            : $now >= $from || $now < $until;
    }

    /**
     * The start of the service window as a clock reading, or null.
     *
     * Postgres hands back "07:00:00" from a time column, but a model that has
     * only just been filled holds whatever was assigned — "07:00" from a form —
     * so the raw value is not one shape. These two are
     * what crosses the wire, so the guest app is given one format to render
     * rather than having to cope with both — the same reason prices leave as
     * integers rather than in whichever way a driver stringified them.
     */
    public function servedFrom(): ?string
    {
        return $this->hasServiceWindow() && $this->available_from !== null
            ? $this->clockReading($this->available_from)
            : null;
    }

    /**
     * The end of the service window as a clock reading, or null.
     */
    public function servedUntil(): ?string
    {
        return $this->hasServiceWindow() && $this->available_until !== null
            ? $this->clockReading($this->available_until)
            : null;
    }

    /**
     * A stored time as HH:MM, whatever shape the driver handed back.
     */
    private function clockReading(string $time): string
    {
        return substr($this->normalisedTime($time), 0, 5);
    }

    /**
     * A stored time as HH:MM:SS, whatever shape the driver handed back.
     *
     * Padded to a fixed width before being compared as strings, which is safe
     * for a 24-hour clock and avoids parsing a date that isn't one.
     */
    private function normalisedTime(string $time): string
    {
        return substr($time.':00:00', 0, 8);
    }

    /**
     * Limit the query to menus a guest should see.
     *
     * Deliberately not filtered by the service window: a breakfast menu that
     * has finished is still shown, marked as served 07:00 to 11:00, because a
     * guest looking for it at noon should find it rather than conclude the
     * tenant has none. isBeingServedAt() is what decides how it reads.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Order the way the tenant arranged them, name only to break ties.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeInMenuOrder(Builder $query): void
    {
        $query->orderBy('position')->orderBy(self::fallbackLocalePath());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'featured_position' => 'integer',
            'combos_position' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
