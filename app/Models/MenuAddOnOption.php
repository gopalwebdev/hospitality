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

/**
 * One choice in an add-on group. The price is what one of it adds; zero is a real price.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $menu_add_on_group_id
 * @property-read MenuAddOnGroup $group
 * @property string $name
 * @property int $price_minor_units
 * @property int|null $tax_rate_basis_points
 * @property int $max_quantity
 * @property bool $is_preselected
 * @property bool $is_available
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'price_minor_units', 'tax_rate_basis_points', 'max_quantity', 'is_preselected', 'is_available', 'position'])]
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
        'is_preselected' => false,
        'is_available' => true,
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

    public function isFree(): bool
    {
        return $this->price_minor_units === 0;
    }

    /**
     * Its own rate, else the tenant's — never the item's. Pass $tenantRate when pricing a list.
     */
    public function taxRateBasisPoints(?int $tenantRate = null): int
    {
        if ($this->tax_rate_basis_points !== null) {
            return $this->tax_rate_basis_points;
        }

        if ($tenantRate !== null) {
            return $tenantRate;
        }

        $stored = TenantSetting::query()->where('tenant_id', $this->tenant_id)->value('tax_rate_basis_points');

        return $stored === null ? TenantSetting::DEFAULT_TAX_RATE_BASIS_POINTS : (int) $stored;
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeAvailable(Builder $query): void
    {
        $query->where('is_available', true);
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
            'tax_rate_basis_points' => 'integer',
            'max_quantity' => 'integer',
            'is_preselected' => 'boolean',
            'is_available' => 'boolean',
            'position' => 'integer',
        ];
    }
}
