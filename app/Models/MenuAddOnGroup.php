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
 * A guest must pick at least one when it `is_required`, and at most `max_picks` (null is no limit), each option counted by its quantity.
 * An item linking this group may cap its own picks tighter or looser, through `MenuItemAddOnGroup::$max_picks`.
 * An option may be taken more than once whenever its own `max_per_item` says so; there is no group-wide switch for it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property bool $is_required
 * @property int|null $max_picks
 * @property-read Collection<int, MenuAddOnOption> $options
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'is_required', 'max_picks'])]
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
        'is_required' => false,
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
     * Whether options adding up to this many picks still leave a guest something to pick, when they must pick one.
     */
    public function canBeMetBy(int $availablePicks): bool
    {
        return ! $this->is_required || $availablePicks >= 1;
    }

    /**
     * How many of one option a guest may take here: its own cap, never more than the picks a guest may make in all.
     *
     * $maxPicks is the effective maximum to check against — an item
     * offering this group may cap it tighter or looser than the group's own,
     * through `MenuItemAddOnGroup::$max_picks`. Left null, the group's
     * own `max_picks` is used.
     */
    public function quantityAllowedFor(MenuAddOnOption $option, ?int $maxPicks = null): int
    {
        $limit = $maxPicks ?? $this->max_picks;

        return $limit === null ? $option->max_per_item : min($option->max_per_item, $limit);
    }

    /**
     * The most picks the loaded options can add up to — what a required group is checked against.
     */
    public function picksOffered(?int $maxPicks = null): int
    {
        return (int) $this->options->sum(fn (MenuAddOnOption $option): int => $this->quantityAllowedFor($option, $maxPicks));
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
            'is_required' => 'boolean',
            'max_picks' => 'integer',
        ];
    }
}
