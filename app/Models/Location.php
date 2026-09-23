<?php

namespace App\Models;

use App\Enums\LocationKind;
use App\Models\Concerns\HasTranslatedNames;
use Carbon\CarbonImmutable;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Where an order goes: a room, a table, or a delivery point like a pool or an
 * entrance. A tenant's own flat list, offered at order time alongside free text.
 *
 * Flat on purpose. A Zone kind and a self-referencing parent_id were built to
 * group rooms by floor and taken out again on the project owner's instruction:
 * a tenant lists the places an order can go, and a floor is not one of them.
 * Grouping is a filter and a search, not another level of table.
 *
 * @property int $id
 * @property int $tenant_id
 * @property LocationKind $kind
 * @property string $name
 * @property string|null $code staff shorthand and the QR deep-link to come, e.g. "204"
 * @property int|null $capacity beds in a room, seats at a table
 * @property bool $is_active
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, Order> $orders
 */
#[Fillable(['kind', 'name', 'code', 'capacity', 'is_active', 'position'])]
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
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
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOfKind(Builder $query, LocationKind $kind): void
    {
        $query->where('kind', $kind);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeInReadingOrder(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => LocationKind::class,
            'capacity' => 'integer',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }
}
