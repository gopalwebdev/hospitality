<?php

namespace App\Models;

use App\Enums\HomeTileAction;
use App\Models\Concerns\HasTranslatedNames;
use App\Observers\HomeTileObserver;
use Carbon\CarbonImmutable;
use Database\Factories\HomeTileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tile in a home screen row: a picture and a destination — a menu, a PDF or a link.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $home_row_id
 * @property string $label
 * @property string|null $image_path
 * @property string|null $document_path
 * @property string|null $url
 * @property HomeTileAction $action
 * @property int|null $menu_id
 * @property int $position
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'home_row_id',
    'label',
    'image_path',
    'document_path',
    'url',
    'action',
    'menu_id',
    'position',
    'is_active',
])]
#[ObservedBy([HomeTileObserver::class])]
class HomeTile extends Model
{
    /** @use HasFactory<HomeTileFactory> */
    use HasFactory;

    use HasTranslatedNames;

    /** @var list<string> */
    public array $translatable = ['label'];

    /** @var array<string, mixed> */
    #[\Override]
    protected $attributes = [
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
     * @return BelongsTo<HomeRow, $this>
     */
    public function homeRow(): BelongsTo
    {
        return $this->belongsTo(HomeRow::class);
    }

    /**
     * @return BelongsTo<Menu, $this>
     */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    /**
     * A tile without a picture is not broken: the guest app draws its label instead.
     */
    public function hasImage(): bool
    {
        return filled($this->image_path);
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
            'action' => HomeTileAction::class,
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
