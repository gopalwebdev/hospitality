<?php

namespace App\Models;

use App\Enums\Currency;
use Carbon\CarbonImmutable;
use Database\Factories\TenantSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How one tenant is configured: contact details, the GST every price is read
 * against, and two optional charges, each a switch plus an amount.
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
class TenantSetting extends Model
{
    /** @use HasFactory<TenantSettingFactory> */
    use HasFactory;

    /** Every rate is stored in basis points: 100% is 10,000, so 5% is 500. */
    public const int BASIS_POINTS_PER_WHOLE = 10_000;

    /** The GST rate a tenant starts on until it types its own. */
    public const int DEFAULT_TAX_RATE_BASIS_POINTS = 500;

    /**
     * Database defaults only land on insert; an unsaved row still has to read under strict mode.
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
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function taxRateBasisPoints(): int
    {
        return $this->tax_rate_basis_points;
    }

    /**
     * The service charge on an amount in minor units, or zero when it is switched off.
     */
    public function serviceChargeOn(int $minorUnits): int
    {
        if (! $this->service_charge_enabled || $this->service_charge_basis_points === 0) {
            return 0;
        }

        return (int) round($minorUnits * $this->service_charge_basis_points / self::BASIS_POINTS_PER_WHOLE);
    }

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
