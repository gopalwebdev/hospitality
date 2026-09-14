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
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property ChargeCalculation $calculation
 * @property int|null $rate_basis_points
 * @property int|null $amount_minor_units
 * @property bool $is_active
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'name',
    'calculation',
    'rate_basis_points',
    'amount_minor_units',
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
    public function amountOn(int $subtotalMinorUnits): int
    {
        return match ($this->calculation) {
            ChargeCalculation::Percentage => (int) round(
                $subtotalMinorUnits * ($this->rate_basis_points ?? 0) / TenantSetting::BASIS_POINTS_PER_WHOLE,
            ),
            ChargeCalculation::FixedAmount => $this->amount_minor_units ?? 0,
        };
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
            'rate_basis_points' => 'integer',
            'amount_minor_units' => 'integer',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }
}
