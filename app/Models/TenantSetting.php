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
 * How one tenant is configured: contact details, trading hours and the GST every
 * price is read against. What is added on top of a bill lives in charges.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property Currency $currency
 * @property string|null $gstin
 * @property int $tax_rate_basis_points
 * @property bool $prices_include_tax
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
    #[\Override]
    protected $attributes = [
        'currency' => Currency::IndianRupee->value,
        'tax_rate_basis_points' => self::DEFAULT_TAX_RATE_BASIS_POINTS,
        'prices_include_tax' => false,
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'tax_rate_basis_points' => 'integer',
            'prices_include_tax' => 'boolean',
            'accepts_orders' => 'boolean',
        ];
    }
}
