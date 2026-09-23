<?php

namespace App\Models;

use App\Enums\PaymentDeviceKind;
use App\Enums\PaymentMethod;
use Carbon\CarbonImmutable;
use Database\Factories\PaymentDeviceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A card machine or a QR code a tenant records payments against, kept for reconciliation.
 *
 * @property int $id
 * @property int $tenant_id
 * @property PaymentDeviceKind $kind
 * @property string $name staff-facing, not translated
 * @property string|null $identifier terminal ID or merchant VPA
 * @property bool $is_active
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, Payment> $payments
 */
#[Fillable(['kind', 'name', 'identifier', 'is_active', 'position'])]
class PaymentDevice extends Model
{
    /** @use HasFactory<PaymentDeviceFactory> */
    use HasFactory;

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
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * The devices a payment made by this method may be recorded against.
     * Cash names no device kind, so it matches nothing here.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeForMethod(Builder $query, PaymentMethod $method): void
    {
        $deviceKind = $method->deviceKind();

        $query->when(
            $deviceKind instanceof PaymentDeviceKind,
            fn (Builder $matching): Builder => $matching->where('kind', $deviceKind),
            fn (Builder $none): Builder => $none->whereRaw('1 = 0'),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => PaymentDeviceKind::class,
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }
}
