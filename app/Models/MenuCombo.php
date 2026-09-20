<?php

namespace App\Models;

use App\Enums\ItemAvailability;
use App\Models\Concerns\HasPricing;
use App\Models\Concerns\HasTranslatedNames;
use App\Observers\MenuComboObserver;
use Carbon\CarbonImmutable;
use Database\Factories\MenuComboFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Items sold together at one price, on a menu rather than in a category. Its price is its own.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $menu_id
 * @property-read Menu $menu
 * @property string $name
 * @property string|null $description
 * @property int $price
 * @property int|null $original_price
 * @property int|null $tax_rate
 * @property string|null $hsn_sac_code
 * @property ItemAvailability $availability
 * @property int|null $max_per_order
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'menu_id',
    'name',
    'description',
    'price',
    'original_price',
    'tax_rate',
    'hsn_sac_code',
    'availability',
    'max_per_order',
    'position',
])]
#[ObservedBy([MenuComboObserver::class])]
class MenuCombo extends Model
{
    /** @use HasFactory<MenuComboFactory> */
    use HasFactory;

    use HasPricing;
    use HasTranslatedNames;

    /** @var list<string> */
    public array $translatable = ['name', 'description'];

    /** @var array<string, mixed> */
    #[\Override]
    protected $attributes = [
        'position' => 0,
        'availability' => ItemAvailability::Available->value,
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Menu, $this>
     */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    /**
     * @return HasMany<MenuComboItem, $this>
     */
    public function comboItems(): HasMany
    {
        return $this->hasMany(MenuComboItem::class);
    }

    /**
     * @return BelongsToMany<MenuItem, $this>
     */
    public function menuItems(): BelongsToMany
    {
        return $this->belongsToMany(MenuItem::class, 'menu_combo_items')
            ->withPivot(['quantity', 'position'])
            ->withTimestamps();
    }

    /**
     * What the items inside cost bought separately — shown beside the price, never used as it.
     */
    public function contentsPrice(): int
    {
        return $this->comboItems->reduce(
            static fn (int $total, MenuComboItem $comboItem): int => $total + ($comboItem->menuItem->price * $comboItem->quantity),
            0,
        );
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOrderable(Builder $query): void
    {
        $query->whereIn('availability', ItemAvailability::orderableValues())
            ->whereRelation('menu', 'is_active', true);
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
            'price' => 'integer',
            'original_price' => 'integer',
            'tax_rate' => 'integer',
            'availability' => ItemAvailability::class,
            'max_per_order' => 'integer',
            'position' => 'integer',
        ];
    }
}
