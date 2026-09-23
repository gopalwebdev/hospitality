<?php

namespace App\Models;

use App\Enums\LocationKind;
use App\Models\Concerns\HasTranslatedNames;
use App\Observers\LocationObserver;
use Carbon\CarbonImmutable;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Where an order goes: a room, a table, or a delivery point like a pool or an
 * entrance — or, with no parent, a zone grouping others ("Floor 2",
 * "Terrace"). A tenant's own list, offered at order time alongside free text.
 *
 * Two levels deep, no more, the menu_categories shape: a location with no
 * parent is top level, and a parent must itself be a Zone. LocationObserver
 * is the only guard, every CHECK constraint having been dropped.
 *
 * @property int $id
 * @property int $tenant_id
 * @property LocationKind $kind
 * @property int|null $parent_id
 * @property-read Location|null $parent
 * @property string $name
 * @property string|null $code staff shorthand and the QR deep-link to come, e.g. "204"
 * @property int|null $capacity beds in a room, seats at a table
 * @property bool $is_active
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, Location> $children
 * @property-read Collection<int, Order> $orders
 */
#[Fillable(['kind', 'parent_id', 'name', 'code', 'capacity', 'is_active', 'position'])]
#[ObservedBy([LocationObserver::class])]
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
     * @return BelongsTo<Location, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Location, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
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
    public function scopeTopLevel(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    /**
     * Only what a guest's order may actually name — never a Zone, which
     * groups other locations rather than naming a destination of its own.
     * Reads LocationKind::deliverableValues() rather than naming a case, so
     * a kind added later is included or excluded by its own answer.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeDeliverable(Builder $query): void
    {
        $query->whereIn('kind', LocationKind::deliverableValues());
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
            'parent_id' => 'integer',
            'capacity' => 'integer',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }
}
