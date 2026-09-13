<?php

namespace App\Models;

use App\Models\Concerns\HasTranslatedNames;
use App\Observers\MenuItemAdditionObserver;
use Carbon\CarbonImmutable;
use Database\Factories\MenuItemAdditionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An extra a dish can be ordered with. The price is what it adds; zero is a real price.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $menu_item_id
 * @property string $name
 * @property int $price_minor_units
 * @property int|null $tax_rate_basis_points
 * @property bool $is_available
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'price_minor_units', 'tax_rate_basis_points', 'is_available', 'position'])]
#[ObservedBy([MenuItemAdditionObserver::class])]
class MenuItemAddition extends Model
{
    /** @use HasFactory<MenuItemAdditionFactory> */
    use HasFactory;

    use HasTranslatedNames;

    /** @var list<string> */
    public array $translatable = ['name'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'price_minor_units' => 0,
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
     * @return BelongsTo<MenuItem, $this>
     */
    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function isFree(): bool
    {
        return $this->price_minor_units === 0;
    }

    /**
     * Its own rate, else the tenant's — never the dish's. Pass $tenantRate when rendering a list.
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
            'price_minor_units' => 'integer',
            'tax_rate_basis_points' => 'integer',
            'is_available' => 'boolean',
            'position' => 'integer',
        ];
    }
}
