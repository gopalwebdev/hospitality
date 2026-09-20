<?php

namespace App\Models;

use App\Observers\MenuItemAddOnGroupObserver;
use Carbon\CarbonImmutable;
use Database\Factories\MenuItemAddOnGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An add-on group offered on one item, placed among that item's other groups.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $menu_item_id
 * @property int $menu_add_on_group_id
 * @property-read MenuItem $menuItem
 * @property-read MenuAddOnGroup $addOnGroup
 * @property int $position
 * @property int|null $max_picks
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['menu_add_on_group_id', 'position', 'max_picks'])]
#[ObservedBy([MenuItemAddOnGroupObserver::class])]
class MenuItemAddOnGroup extends Model
{
    /** @use HasFactory<MenuItemAddOnGroupFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    #[\Override]
    protected $attributes = [
        'position' => 0,
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<MenuItem, $this>
     */
    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    /**
     * @return BelongsTo<MenuAddOnGroup, $this>
     */
    public function addOnGroup(): BelongsTo
    {
        return $this->belongsTo(MenuAddOnGroup::class, 'menu_add_on_group_id');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeInMenuOrder(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }

    /**
     * The picks a guest may make from the group on this item: this link's own cap, or the group's own when it has none.
     */
    public function effectiveMaxPicks(MenuAddOnGroup $group): ?int
    {
        return $this->max_picks ?? $group->max_picks;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'menu_item_id' => 'integer',
            'menu_add_on_group_id' => 'integer',
            'position' => 'integer',
            'max_picks' => 'integer',
        ];
    }
}
