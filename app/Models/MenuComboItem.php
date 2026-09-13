<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MenuComboItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line inside a combo: "2 × Coke".
 *
 * A model rather than a bare pivot because it carries two columns of its own —
 * how many, and where it sits in the list a guest reads. Neither belongs on a
 * plain belongsToMany.
 *
 * It carries no price. The combo's price is the combo's; see MenuCombo.
 *
 * tenant_id is carried directly as well as through both parents, with a
 * composite foreign key on each, so a combo can neither belong to another
 * tenant nor contain another tenant's dish.
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
class MenuComboItem extends Model
{
    /** @use HasFactory<MenuComboItemFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'quantity' => 1,
        'position' => 0,
    ];

    /**
     * Take the tenant from the combo this line belongs to.
     *
     * Same reason as MenuItemAddition::booted(): the repeater on the combo form
     * writes these rows, and Filament's tenancy does not stamp them.
     */
    protected static function booted(): void
    {
        static::creating(function (self $comboItem): void {
            if (filled($comboItem->tenant_id) || blank($comboItem->menu_combo_id)) {
                return;
            }

            $comboItem->tenant_id = MenuCombo::query()
                ->withoutGlobalScopes()
                ->whereKey($comboItem->menu_combo_id)
                ->value('tenant_id');
        });
    }

    /**
     * The tenant selling this.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The combo this line is part of.
     *
     * @return BelongsTo<MenuCombo, $this>
     */
    public function menuCombo(): BelongsTo
    {
        return $this->belongsTo(MenuCombo::class);
    }

    /**
     * The dish this line names.
     *
     * @return BelongsTo<MenuItem, $this>
     */
    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    /**
     * Order the way the tenant arranged the contents.
     *
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
