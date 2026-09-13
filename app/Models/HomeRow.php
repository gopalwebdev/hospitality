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
 * One band of the home screen. The row owns the layout; its tiles own their destinations.
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

    /** @var list<string> */
    public array $translatable = ['title'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'layout' => HomeRowLayout::Banner->value,
        'position' => 0,
        'is_active' => true,
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return HasMany<HomeTile, $this>
     */
    public function tiles(): HasMany
    {
        return $this->hasMany(HomeTile::class);
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
