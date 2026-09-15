<?php

namespace App\Models;

use App\Models\Concerns\HasTranslatedNames;
use App\Observers\MenuAddOnOptionObserver;
use Carbon\CarbonImmutable;
use Database\Factories\MenuAddOnOptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One choice in an add-on group. The price is what one of it adds; zero is a real price.
 * It has no tax rate of its own: an add-on is part of the item it is added to, a composite supply taxed at the item's rate.
 * Its count, when kept, is shared by every item offering its group.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $menu_add_on_group_id
 * @property-read MenuAddOnGroup $group
 * @property string $name
 * @property int $price_minor_units
 * @property int $max_quantity
 * @property bool $is_default
 * @property bool $is_available
 * @property int|null $stock_quantity
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'price_minor_units', 'max_quantity', 'is_default', 'is_available', 'stock_quantity', 'position'])]
#[ObservedBy([MenuAddOnOptionObserver::class])]
class MenuAddOnOption extends Model
{
    /** @use HasFactory<MenuAddOnOptionFactory> */
    use HasFactory;

    use HasTranslatedNames;

    /** @var list<string> */
    public array $translatable = ['name'];

    /** @var array<string, mixed> */
    #[\Override]
    protected $attributes = [
        'price_minor_units' => 0,
        'max_quantity' => 1,
        'is_default' => false,
        'is_available' => true,
        'stock_quantity' => null,
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
     * @return BelongsTo<MenuAddOnGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(MenuAddOnGroup::class, 'menu_add_on_group_id');
    }

    /**
     * Every change to its count, and why.
     *
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'menu_add_on_option_id');
    }

    public function isFree(): bool
    {
        return $this->price_minor_units === 0;
    }

    /**
     * What a guest can have right now: switched on, and not counted down to nothing.
     *
     * None left hides an option without touching `is_available`, which is the
     * admin's own switch — so restocking brings back an option they left on,
     * and never one they turned off.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeAvailable(Builder $query): void
    {
        $query->where('is_available', true)
            ->where(fn (Builder $left): Builder => $left->whereNull('stock_quantity')->orWhere('stock_quantity', '>', 0));
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
            'menu_add_on_group_id' => 'integer',
            'price_minor_units' => 'integer',
            'max_quantity' => 'integer',
            'is_default' => 'boolean',
            'is_available' => 'boolean',
            'stock_quantity' => 'integer',
            'position' => 'integer',
        ];
    }
}
