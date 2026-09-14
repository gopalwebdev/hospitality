<?php

namespace App\Models;

use App\Models\Concerns\HasTranslatedNames;
use Carbon\CarbonImmutable;
use Database\Factories\MenuAddOnGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A set of choices items are customised with — a spice level, a bread, extras — kept once and linked to every item that offers it.
 * A guest makes at least `min_selections` picks (0 is optional) and at most `max_selections` (null is no limit), each option counted by its quantity.
 * Only a group that `allows_quantities` lets a guest take one option more than once ("Extra cheese × 2").
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property int $min_selections
 * @property int|null $max_selections
 * @property bool $allows_quantities
 * @property-read Collection<int, MenuAddOnOption> $options
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'min_selections', 'max_selections', 'allows_quantities'])]
class MenuAddOnGroup extends Model
{
    /** @use HasFactory<MenuAddOnGroupFactory> */
    use HasFactory;

    use HasTranslatedNames;

    /** @var list<string> */
    public array $translatable = ['name'];

    /** @var array<string, mixed> */
    #[\Override]
    protected $attributes = [
        'min_selections' => 0,
        'allows_quantities' => false,
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * What a guest picks from.
     *
     * @return HasMany<MenuAddOnOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(MenuAddOnOption::class);
    }

    /**
     * Where the group is offered: one row per item, placed among that item's own groups.
     *
     * @return HasMany<MenuItemAddOnGroup, $this>
     */
    public function itemLinks(): HasMany
    {
        return $this->hasMany(MenuItemAddOnGroup::class);
    }

    /**
     * Whether a guest has to pick from it before the item can be added.
     */
    public function isRequired(): bool
    {
        return $this->min_selections >= 1;
    }

    /**
     * Whether options adding up to this many picks can still meet the minimum.
     */
    public function canBeMetBy(int $availablePicks): bool
    {
        return $availablePicks >= $this->min_selections;
    }

    /**
     * How many of one option a guest may take here: its own cap, or one while the group does not allow quantities.
     */
    public function quantityAllowedFor(MenuAddOnOption $option): int
    {
        return $this->allows_quantities ? $option->max_quantity : 1;
    }

    /**
     * The most picks the loaded options can add up to — what a required group's minimum is checked against.
     */
    public function picksOffered(): int
    {
        return (int) $this->options->sum(fn (MenuAddOnOption $option): int => $this->quantityAllowedFor($option));
    }

    /**
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
            'min_selections' => 'integer',
            'max_selections' => 'integer',
            'allows_quantities' => 'boolean',
        ];
    }
}
