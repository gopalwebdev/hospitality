<?php

namespace App\Models;

use App\Enums\Currency;
use Carbon\CarbonImmutable;
use Database\Factories\RestaurantSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How one restaurant is configured.
 *
 * Exactly one row per restaurant, enforced by a unique key on tenant_id.
 *
 * It carries the tax and the charges every price on the menu is read against:
 * the default GST rate a dish falls back to, whether the prices already include
 * it, and the two optional charges. Each charge is a switch and an amount
 * rather than an amount alone, so "we do not levy a service charge" is a thing
 * a restaurant can say — which matters here, because the CCPA's 2022 guidelines
 * make a service charge voluntary rather than something a bill may assume.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property Currency $currency
 * @property string|null $gstin
 * @property int $tax_rate_basis_points
 * @property bool $prices_include_tax
 * @property bool $service_charge_enabled
 * @property int $service_charge_basis_points
 * @property bool $parcel_charge_enabled
 * @property int $parcel_charge_minor_units
 * @property bool $accepts_orders
 * @property string|null $opens_at
 * @property string|null $closes_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'contact_email',
    'contact_phone',
    'currency',
    'gstin',
    'tax_rate_basis_points',
    'prices_include_tax',
    'service_charge_enabled',
    'service_charge_basis_points',
    'parcel_charge_enabled',
    'parcel_charge_minor_units',
    'accepts_orders',
    'opens_at',
    'closes_at',
])]
class RestaurantSetting extends Model
{
    /** @use HasFactory<RestaurantSettingFactory> */
    use HasFactory;

    /**
     * How many basis points make one whole: 100% is 10,000, so 5% is 500.
     *
     * Every rate in the application is stored this way — the GST rate here and
     * on each dish, and the service charge below — so that percentages stay
     * exact integers, exactly as money stays exact integers in minor units. A
     * float rate would put rounding error in the middle of an amount that has
     * to reconcile to the paisa.
     */
    public const int BASIS_POINTS_PER_WHOLE = 10_000;

    /**
     * What a restaurant charges GST at until it says otherwise.
     *
     * 5% is standalone restaurant service without input tax credit, which is
     * what almost every restaurant on this platform charges. It is a starting
     * point, not a constraint: rates are typed, because India's GST 2.0 reform
     * of September 2025 restructured the slabs and the next notification may do
     * so again.
     */
    public const int DEFAULT_TAX_RATE_BASIS_POINTS = 500;

    /**
     * The database defaults only land on insert, so an unsaved row would throw
     * under Model::shouldBeStrict() when the settings page reads it before the
     * first save. Same reason as User::$attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'currency' => Currency::IndianRupee->value,
        'tax_rate_basis_points' => self::DEFAULT_TAX_RATE_BASIS_POINTS,
        'prices_include_tax' => false,
        'service_charge_enabled' => false,
        'service_charge_basis_points' => 0,
        'parcel_charge_enabled' => false,
        'parcel_charge_minor_units' => 0,
        'accepts_orders' => true,
    ];

    /**
     * The restaurant these settings belong to.
     *
     * @return BelongsTo<Restaurant, $this>
     */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class, 'tenant_id');
    }

    /**
     * The GST rate a dish falls back to when it names none of its own.
     */
    public function taxRateBasisPoints(): int
    {
        return $this->tax_rate_basis_points;
    }

    /**
     * The service charge on an amount, or zero when it is switched off.
     *
     * A percentage of what has been ordered, in the same minor units, rounded
     * once. Switched off is not the same as set to nothing: the switch is what
     * lets a bill say the restaurant does not levy one at all.
     */
    public function serviceChargeOn(int $minorUnits): int
    {
        if (! $this->service_charge_enabled || $this->service_charge_basis_points === 0) {
            return 0;
        }

        return (int) round(
            $minorUnits * $this->service_charge_basis_points / self::BASIS_POINTS_PER_WHOLE,
        );
    }

    /**
     * The flat charge for packing an order to take away, or zero when off.
     */
    public function parcelCharge(): int
    {
        return $this->parcel_charge_enabled ? $this->parcel_charge_minor_units : 0;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'tax_rate_basis_points' => 'integer',
            'prices_include_tax' => 'boolean',
            'service_charge_enabled' => 'boolean',
            'service_charge_basis_points' => 'integer',
            'parcel_charge_enabled' => 'boolean',
            'parcel_charge_minor_units' => 'integer',
            'accepts_orders' => 'boolean',
        ];
    }
}
