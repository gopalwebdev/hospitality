<?php

namespace App\Models;

use App\Enums\HomeRowLayout;
use App\Models\Concerns\HasTranslatedNames;
use Carbon\CarbonImmutable;
use Database\Factories\HomeRowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One band of the home screen a guest lands on.
 *
 * A tenant arranges its shop window as rows, drags them into the order it
 * wants them read, and chooses what each one looks like. The row owns the
 * layout; the tiles inside it own their destinations. See App\Enums\HomeRowLayout.
 *
 * The title is optional — a banner into the menu speaks for itself — and is a
 * translated column like every other word a guest reads.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string|null $title
 * @property HomeRowLayout $layout
 * @property int $position
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, HomeTile> $tiles
 */
#[Fillable(['title', 'layout', 'position', 'is_active'])]
class HomeRow extends Model
{
    /** @use HasFactory<HomeRowFactory> */
    use HasFactory;

    use HasTranslatedNames;

    /**
     * @var list<string>
     */
    public array $translatable = ['title'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'layout' => HomeRowLayout::Banner->value,
        'position' => 0,
        'is_active' => true,
    ];

    /**
     * The tenant whose home screen this row is on.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The tiles in this row, in the order the tenant dragged them into.
     *
     * @return HasMany<HomeTile, $this>
     */
    public function tiles(): HasMany
    {
        return $this->hasMany(HomeTile::class);
    }

    /**
     * Limit the query to rows a guest should see.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Order the way the tenant arranged the home screen.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeInDisplayOrder(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'layout' => HomeRowLayout::class,
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
