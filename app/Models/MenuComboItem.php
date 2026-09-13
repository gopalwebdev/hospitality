<?php

namespace App\Models;

use App\Observers\MenuComboItemObserver;
use Carbon\CarbonImmutable;
use Database\Factories\MenuComboItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line inside a combo: "2 × Coke". It carries no price.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $menu_combo_id
 * @property int $menu_item_id
 * @property-read MenuCombo $menuCombo
 * @property-read MenuItem $menuItem
 * @property int $quantity
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['menu_item_id', 'quantity', 'position'])]
#[ObservedBy([MenuComboItemObserver::class])]
class MenuComboItem extends Model
{
    /** @use HasFactory<MenuComboItemFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'quantity' => 1,
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
     * @return BelongsTo<MenuCombo, $this>
     */
    public function menuCombo(): BelongsTo
    {
        return $this->belongsTo(MenuCombo::class);
    }

    /**
     * @return BelongsTo<MenuItem, $this>
     */
    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeInMenuOrder(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'position' => 'integer',
        ];
    }
}
