<?php

namespace App\Models;

use App\Enums\HomeTileAction;
use App\Models\Concerns\HasTranslatedNames;
use Carbon\CarbonImmutable;
use Database\Factories\HomeTileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One tile inside a row of the home screen a guest lands on.
 *
 * A tile is a picture and a destination. How it is *drawn* is not its own
 * business — the row it sits in owns that, through HomeRowLayout — so there is
 * no shape here. What it opens is: a menu, an uploaded PDF, or a link out of
 * the app, one target column each. See App\Enums\HomeTileAction.
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
class HomeTile extends Model
{
    /** @use HasFactory<HomeTileFactory> */
    use HasFactory;

    use HasTranslatedNames;

    /**
     * @var list<string>
     */
    public array $translatable = ['label'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'position' => 0,
        'is_active' => true,
    ];

    /**
     * Keep a tile's action and its destination in step.
     *
     * A Menu tile with no menu, or a PDF tile with no file, is a tile that goes
     * nowhere — and a tile switched from one to the other would otherwise keep
     * the destination it no longer uses. This would be a CHECK constraint if
     * Laravel's Blueprint could express one and SQLite could add one after the
     * table exists; neither is true, so the guard lives here and the admin form
     * states the same rule as validation.
     */
    protected static function booted(): void
    {
        // Take the tenant from the row this sits in. It is the same
        // tenant by definition — the composite foreign key insists on it —
        // so nothing that creates a tile has to remember. That includes the
        // tiles relation manager, where Filament's tenancy stamps the row a
        // resource is saving but not the rows hanging off it.
        static::creating(function (self $tile): void {
            if (filled($tile->tenant_id) || blank($tile->home_row_id)) {
                return;
            }

            $tile->tenant_id = HomeRow::query()
                ->withoutGlobalScopes()
                ->whereKey($tile->home_row_id)
                ->value('tenant_id');
        });

        static::saving(function (self $tile): void {
            $required = $tile->action->targetColumn();

            foreach (HomeTileAction::everyTargetColumn() as $column) {
                if ($column !== $required) {
                    $tile->{$column} = null;
                }
            }

            throw_if(
                blank($tile->{$required}),
                LogicException::class,
                sprintf('A %s tile needs a %s.', $tile->action->value, $required),
            );
        });
    }

    /**
     * The tenant whose home screen this is on.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The row this tile sits in, which decides how it is drawn.
     *
     * @return BelongsTo<HomeRow, $this>
     */
    public function homeRow(): BelongsTo
    {
        return $this->belongsTo(HomeRow::class);
    }

    /**
     * The menu this opens, where it opens one.
     *
     * @return BelongsTo<Menu, $this>
     */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    /**
     * Whether this tile has a picture to show.
     *
     * A tile without one is not broken: the guest app draws the label on the
     * brand colour instead, so a tenant can arrange its home screen before
     * it has photography.
     */
    public function hasImage(): bool
    {
        return filled($this->image_path);
    }

    /**
     * Limit the query to tiles a guest should see.
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
            'action' => HomeTileAction::class,
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
