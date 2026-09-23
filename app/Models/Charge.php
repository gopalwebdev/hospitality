<?php

namespace App\Models;

use App\Enums\ChargeCalculation;
use App\Models\Concerns\HasTranslatedNames;
use App\Observers\ChargeObserver;
use Carbon\CarbonImmutable;
use Database\Factories\ChargeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Something added to a guest's bill beyond what they order: a service charge, a packing charge,
 * a room-service fee. A share of the bill or a fixed amount, on the menus attached to it.
 *
 * `tax_rate` and `hsn_sac_code` are **normally null**, meaning "the tenant's own
 * rate" — a charge is its own supply, taxed with the order rather than folded
 * into any one item's rate, and never touched by `tenant_settings.tax_overrides_item_rates`,
 * which only ever reaches `MenuItem`/`MenuCombo::taxRate()`. They are here for
 * the tenant that wants to state a charge's own code — a service charge filed
 * under its own SAC rather than the tenant's blended default. See `.ai/rules/tax-codes.md`.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property ChargeCalculation $calculation
 * @property int|null $rate
 * @property int|null $amount
 * @property int|null $tax_rate basis points; null follows the tenant's own rate
 * @property string|null $hsn_sac_code
 * @property bool $is_active
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'name',
    'calculation',
    'rate',
    'amount',
    'tax_rate',
    'hsn_sac_code',
    'is_active',
    'position',
])]
#[ObservedBy([ChargeObserver::class])]
class Charge extends Model
{
    /** @use HasFactory<ChargeFactory> */
    use HasFactory;

    use HasTranslatedNames;

    /** @var list<string> */
    public array $translatable = ['name'];

    /** @var array<string, mixed> */
    #[\Override]
    protected $attributes = [
        'is_active' => true,
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
     * The menus whose bills this charge is added to.
     *
     * @return BelongsToMany<Menu, $this>
     */
    public function menus(): BelongsToMany
    {
        return $this->belongsToMany(Menu::class)->withTimestamps();
    }

    /**
     * What this adds to a bill of the given amount, in minor units.
     *
     * A share is rounded to the nearest minor unit here, once; a fixed amount is
     * the same whatever the bill comes to.
     */
    public function amountOn(int $subtotal): int
    {
        return match ($this->calculation) {
            ChargeCalculation::Percentage => (int) round(
                $subtotal * ($this->rate ?? 0) / TenantSetting::BASIS_POINTS_PER_WHOLE,
            ),
            ChargeCalculation::FixedAmount => $this->amount ?? 0,
        };
    }

    /**
     * The rate this charge is taxed at: its own where it states one, otherwise the tenant's.
     */
    public function taxRate(int $tenantRate): int
    {
        return $this->tax_rate ?? $tenantRate;
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * The charges a bill from this menu carries: the ones attached to it.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeForMenu(Builder $query, int $menuId): void
    {
        $query->whereHas('menus', fn (Builder $menus): Builder => $menus->whereKey($menuId));
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
            'calculation' => ChargeCalculation::class,
            'rate' => 'integer',
            'amount' => 'integer',
            'tax_rate' => 'integer',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }
}
