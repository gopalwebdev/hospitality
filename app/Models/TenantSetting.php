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
 * How one tenant is configured: how to reach it, and the GST every price is read against.
 *
 * When its doors are open is not here — that is a week of `tenant_opening_hours`
 * rows — and what is added on top of a bill lives in charges.
 *
 * GST is levied in halves on an intra-state supply, CGST to the centre and SGST
 * to the state, so both are stored and the rate anything is taxed at is the two
 * added up. `tax_overrides_item_rates` makes that rate the rate for every item,
 * whatever an item's own says.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $alternate_phone
 * @property string|null $landline_phone
 * @property Currency $currency
 * @property string|null $gstin
 * @property int $cgst_rate_basis_points
 * @property int $sgst_rate_basis_points
 * @property bool $tax_overrides_item_rates
 * @property bool $prices_include_tax
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'contact_email',
    'contact_phone',
    'alternate_phone',
    'landline_phone',
    'currency',
    'gstin',
    'cgst_rate_basis_points',
    'sgst_rate_basis_points',
    'tax_overrides_item_rates',
    'prices_include_tax',
])]
class TenantSetting extends Model
{
    /** @use HasFactory<TenantSettingFactory> */
    use HasFactory;

    /** Every rate is stored in basis points: 100% is 10,000, so 5% is 500. */
    public const int BASIS_POINTS_PER_WHOLE = 10_000;

    /** The GST a tenant starts on, in all, until it types its own. */
    public const int DEFAULT_TAX_RATE_BASIS_POINTS = 500;

    /**
     * Database defaults only land on insert; an unsaved row still has to read under strict mode.
     *
     * The halves are exactly that — half the default each — so the two of them
     * and DEFAULT_TAX_RATE_BASIS_POINTS cannot drift apart.
     *
     * @var array<string, mixed>
     */
    #[\Override]
    protected $attributes = [
        'currency' => Currency::IndianRupee->value,
        'cgst_rate_basis_points' => self::DEFAULT_TAX_RATE_BASIS_POINTS / 2,
        'sgst_rate_basis_points' => self::DEFAULT_TAX_RATE_BASIS_POINTS / 2,
        'tax_overrides_item_rates' => false,
        'prices_include_tax' => false,
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * What anything is taxed at in all: the centre's half and the state's, added up.
     */
    public function taxRateBasisPoints(): int
    {
        return $this->cgst_rate_basis_points + $this->sgst_rate_basis_points;
    }

    /**
     * Whether this rate is what every item is taxed at, whatever an item's own says.
     */
    public function overridesItemTaxRates(): bool
    {
        return $this->tax_overrides_item_rates;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'cgst_rate_basis_points' => 'integer',
            'sgst_rate_basis_points' => 'integer',
            'tax_overrides_item_rates' => 'boolean',
            'prices_include_tax' => 'boolean',
        ];
    }
}
