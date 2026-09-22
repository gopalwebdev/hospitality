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
 * Its count, when kept, is shared by every item offering its group.
 *
 * `tax_rate` and `hsn_sac_code` are **normally null**, and that is the right
 * answer: an add-on is part of the item it is added to, a composite supply
 * taxed at the item's rate (CGST Act, s. 8(a)). They exist for the option that
 * is genuinely a different supply — a haircut offered beside a meal — and
 * `taxRate()` is what falls back to the item's.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $menu_add_on_group_id
 * @property-read MenuAddOnGroup $group
 * @property string $name
 * @property int $price
 * @property int|null $tax_rate basis points; null follows the item
 * @property string|null $hsn_sac_code
 * @property int $max_per_item
 * @property bool $is_default
 * @property bool $is_available
 * @property int|null $stock_quantity
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'price', 'tax_rate', 'hsn_sac_code', 'max_per_item', 'is_default', 'is_available', 'stock_quantity', 'position'])]
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
        'price' => 0,
        'tax_rate' => null,
        'hsn_sac_code' => null,
        'max_per_item' => 1,
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
        return $this->price === 0;
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
     * The rate this option is taxed at: its own where it states one, otherwise the item's.
     *
     * Null is the ordinary case and means "part of the item", which is what a
     * composite supply is. A rate of its own is for an option that is really a
     * separate supply.
     */
    public function taxRate(int $itemRate): int
    {
        return $this->tax_rate ?? $itemRate;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'menu_add_on_group_id' => 'integer',
            'price' => 'integer',
            'tax_rate' => 'integer',
            'max_per_item' => 'integer',
            'is_default' => 'boolean',
            'is_available' => 'boolean',
            'stock_quantity' => 'integer',
            'position' => 'integer',
        ];
    }
}
