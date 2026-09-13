<?php

namespace App\Models;

use App\Models\Concerns\HasTranslatedNames;
use Carbon\CarbonImmutable;
use Database\Factories\MenuItemAdditionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An extra a dish can be ordered with: extra cheese, a large portion.
 *
 * The price is what the addition adds to the dish, not what the dish becomes,
 * and it is an integer count of the currency's minor unit like every other
 * money column here. Zero is a real price — "no onions" costs nothing and is
 * still worth listing.
 *
 * tenant_id is carried directly as well as through the dish, and the
 * composite foreign key on (menu_item_id, tenant_id) is what stops the two
 * disagreeing. Same shape as menu_items; see .ai/rules/models.md.
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
class MenuItemAddition extends Model
{
    /** @use HasFactory<MenuItemAdditionFactory> */
    use HasFactory;

    use HasTranslatedNames;

    /**
     * @var list<string>
     */
    public array $translatable = ['name'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'price_minor_units' => 0,
        'is_available' => true,
        'position' => 0,
    ];

    /**
     * Take the tenant from the dish this belongs to.
     *
     * It is the same tenant by definition — the composite foreign key
     * insists on it — so deriving it here means nothing that creates an
     * addition has to remember. That includes the repeater on the dish form:
     * Filament's tenancy stamps the dish it is saving, but not the related rows
     * a repeater writes alongside it.
     */
    protected static function booted(): void
    {
        static::creating(function (self $addition): void {
            if (filled($addition->tenant_id) || blank($addition->menu_item_id)) {
                return;
            }

            $menuItemId = $addition->menu_item_id;

            // A repeater saves every addition of one dish in the same request,
            // and they all belong to the same tenant.
            $addition->tenant_id = once(fn (): mixed => MenuItem::query()
                ->withoutGlobalScopes()
                ->whereKey($menuItemId)
                ->value('tenant_id'));
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
     * The dish this is an extra for.
     *
     * @return BelongsTo<MenuItem, $this>
     */
    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    /**
     * Whether this costs anything at all.
     *
     * A free addition is shown as a plain choice rather than with "+ ₹0.00"
     * beside it, which reads as a mistake.
     */
    public function isFree(): bool
    {
        return $this->price_minor_units === 0;
    }

    /**
     * The GST rate this addition is taxed at, in basis points.
     *
     * Its own rate when it has one, and otherwise the tenant's default —
     * the same fallback MenuItem uses, and for the same reason: an addition is
     * usually taxed exactly like the dish it goes on, and only a genuinely
     * different line (a sealed bottle taxed as goods) needs saying.
     *
     * Not the dish's rate, deliberately. An addition that overrides is
     * overriding because it differs from the food, so inheriting from the dish
     * would be inheriting the wrong number.
     *
     * Pass $tenantRate when rendering a list; every row shares it.
     */
    public function taxRateBasisPoints(?int $tenantRate = null): int
    {
        if ($this->tax_rate_basis_points !== null) {
            return $this->tax_rate_basis_points;
        }

        if ($tenantRate !== null) {
            return $tenantRate;
        }

        $stored = TenantSetting::query()
            ->where('tenant_id', $this->tenant_id)
            ->value('tax_rate_basis_points');

        return $stored === null
            ? TenantSetting::DEFAULT_TAX_RATE_BASIS_POINTS
            : (int) $stored;
    }

    /**
     * Limit the query to additions a guest may actually ask for.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeAvailable(Builder $query): void
    {
        $query->where('is_available', true);
    }

    /**
     * Order the way the tenant arranged them, name only to break ties.
     *
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
