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

    /** @var list<string> */
    public array $translatable = ['name', 'description'];

    /**
     * The rails start level with the first category; ties read rails first. See readingOrder().
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'position' => 0,
        'featured_position' => 0,
        'combos_position' => 0,
        'is_active' => true,
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Every category on this menu, at both levels.
     *
     * @return HasMany<MenuCategory, $this>
     */
    public function menuCategories(): HasMany
    {
        return $this->hasMany(MenuCategory::class);
    }

    /**
     * @return HasMany<MenuCategory, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(MenuCategory::class)->whereNull('parent_id');
    }

    /**
     * @return HasMany<MenuCategory, $this>
     */
    public function subCategories(): HasMany
    {
        return $this->hasMany(MenuCategory::class)->whereNotNull('parent_id');
    }

    /**
     * @return HasMany<MenuCombo, $this>
     */
    public function combos(): HasMany
    {
        return $this->hasMany(MenuCombo::class);
    }

    /**
     * @return HasMany<HomeTile, $this>
     */
    public function homeTiles(): HasMany
    {
        return $this->hasMany(HomeTile::class);
    }

    /**
     * Every dish on this menu, reached through its categories — a dish never carries its menu.
     *
     * @return HasManyThrough<MenuItem, MenuCategory, $this>
     */
    public function menuItems(): HasManyThrough
    {
        return $this->hasManyThrough(MenuItem::class, MenuCategory::class);
    }

    /**
     * The featured rail, the combos rail and the categories, in the order a guest reads them.
     *
     * All three share one number space. Ties break rails first, so a menu nobody
     * has arranged opens with its featured dishes, then its combos.
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

        // PHP sorts stably, so categories sharing a position keep the query's order.
        usort($blocks, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(static fn (array $block): MenuBlock|MenuCategory => $block[2], $blocks);
    }

    public function hasServiceWindow(): bool
    {
        return filled($this->available_from) && filled($this->available_until);
    }

    /**
     * Whether this menu is being served at a moment. A window running past midnight wraps.
     */
    public function isBeingServedAt(?CarbonImmutable $moment = null): bool
    {
        if (! $this->hasServiceWindow()) {
            return true;
        }

        $from = $this->available_from;
        $until = $this->available_until;

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
     * The start of the service window as HH:MM, whatever shape the driver returned.
     */
    public function servedFrom(): ?string
    {
        return $this->hasServiceWindow() && $this->available_from !== null
            ? $this->clockReading($this->available_from)
            : null;
    }

    /**
     * The end of the service window as HH:MM.
     */
    public function servedUntil(): ?string
    {
        return $this->hasServiceWindow() && $this->available_until !== null
            ? $this->clockReading($this->available_until)
            : null;
    }

    /**
     * Not filtered by the service window: a finished breakfast menu still shows, marked with its hours.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
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

    private function clockReading(string $time): string
    {
        return substr($this->normalisedTime($time), 0, 5);
    }

    /**
     * HH:MM:SS, padded to a fixed width so two times compare as strings.
     */
    private function normalisedTime(string $time): string
    {
        return substr($time.':00:00', 0, 8);
    }
}
