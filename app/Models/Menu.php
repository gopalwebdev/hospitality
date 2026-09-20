<?php

namespace App\Models;

use App\Enums\MenuRailType;
use App\Models\Concerns\HasTranslatedNames;
use App\Models\Concerns\ReadsClockTimes;
use Carbon\CarbonImmutable;
use Database\Factories\MenuFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * One of a tenant's menus: Lunch, Dinner, Drinks.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 * @property string|null $available_from
 * @property string|null $available_until
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'description', 'is_active', 'available_from', 'available_until'])]
class Menu extends Model
{
    /** @use HasFactory<MenuFactory> */
    use HasFactory;

    use HasTranslatedNames;
    use ReadsClockTimes;

    /** @var list<string> */
    public array $translatable = ['name', 'description'];

    /** @var array<string, mixed> */
    #[\Override]
    protected $attributes = [
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
     * What has been placed on this menu's top level beside its categories. See readingOrder().
     *
     * @return HasMany<MenuRail, $this>
     */
    public function rails(): HasMany
    {
        return $this->hasMany(MenuRail::class);
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
     * The charges limited to this menu. Charges on every menu are not listed here; see Charge::scopeForMenu().
     *
     * @return BelongsToMany<Charge, $this>
     */
    public function charges(): BelongsToMany
    {
        return $this->belongsToMany(Charge::class)->withTimestamps();
    }

    /**
     * Every item on this menu, reached through its categories — an item never carries its menu.
     *
     * @return HasManyThrough<MenuItem, MenuCategory, $this>
     */
    public function menuItems(): HasManyThrough
    {
        return $this->hasManyThrough(MenuItem::class, MenuCategory::class);
    }

    /**
     * The rails and top-level categories, in the order a guest reads them.
     *
     * Both share one number space. A rail every menu has but nobody has placed
     * has no row, and reads at 0. Ties break rails first, in MenuRailType
     * order, so a menu nobody has arranged opens with its featured items, then
     * its combos, then its categories.
     *
     * @param  iterable<MenuCategory>  $categories  this menu's top-level categories, in order
     * @param  iterable<MenuRail>  $rails  this menu's saved rails
     * @return list<MenuRail|MenuCategory>
     */
    public function readingOrder(iterable $categories, iterable $rails): array
    {
        $types = MenuRailType::cases();
        $rankOf = array_flip(array_map(static fn (MenuRailType $type): string => $type->value, $types));
        $entries = [];
        $placed = [];

        foreach ($rails as $rail) {
            $entries[] = [$rail->position, $rankOf[$rail->type->value], $rail];
            $placed[$rail->type->value] = true;
        }

        foreach ($types as $type) {
            if ($type->isOnEveryMenu() && ! isset($placed[$type->value])) {
                $entries[] = [0, $rankOf[$type->value], new MenuRail(['menu_id' => $this->getKey(), 'type' => $type])];
            }
        }

        foreach ($categories as $category) {
            $entries[] = [$category->position, count($types), $category];
        }

        // PHP sorts stably, so rows sharing a position keep the order they were handed in.
        usort($entries, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(static fn (array $entry): MenuRail|MenuCategory => $entry[2], $entries);
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
     * Menus are not put in order by hand, so a list of them reads alphabetically, in English.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeByName(Builder $query): void
    {
        $query->orderBy(self::fallbackLocalePath());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
