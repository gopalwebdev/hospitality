<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\TaxCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One HSN or SAC code, what it covers, and the GST rate it usually carries.
 *
 * It exists so nobody types a rate per item from memory. Picking a code on the
 * item form copies its rate and its code onto that item — and then lets go: the
 * item's own `tax_rate` and `hsn_sac_code` are what a bill actually reads, so
 * correcting a row here never reprices anything already filled in, and an item
 * that needs a different rate is simply edited.
 *
 * **A row with no tenant is the catalogue** the product team seeds, offered to
 * every tenant and read-only to them. A row naming a tenant is that tenant's
 * own addition. `availableTo()` is the one query that puts the two together,
 * and `TaxCodePolicy` is what stops a tenant editing the catalogue.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $code
 * @property string $description
 * @property int $tax_rate basis points: 5% is 500
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['code', 'description', 'tax_rate'])]
class TaxCode extends Model
{
    /** @use HasFactory<TaxCodeFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The catalogue, plus the rows this tenant has added for itself.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeAvailableTo(Builder $query, Tenant $tenant): void
    {
        $query->where(fn (Builder $scoped): Builder => $scoped
            ->whereNull('tenant_id')
            ->orWhere('tenant_id', $tenant->getKey()));
    }

    /**
     * Whether the product team owns this row rather than a tenant.
     */
    public function isFromCatalogue(): bool
    {
        return $this->tenant_id === null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'tax_rate' => 'integer',
        ];
    }
}
