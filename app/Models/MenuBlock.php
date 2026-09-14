<?php

namespace App\Models;

use App\Enums\MenuBlockType;
use App\Observers\MenuBlockObserver;
use Carbon\CarbonImmutable;
use Database\Factories\MenuBlockFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something placed on a menu's top level beside its categories, in the same order as them.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $menu_id
 * @property MenuBlockType $type
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['menu_id', 'type', 'position'])]
#[ObservedBy([MenuBlockObserver::class])]
class MenuBlock extends Model
{
    /** @use HasFactory<MenuBlockFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    #[\Override]
    protected $attributes = [
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
     * @return BelongsTo<Menu, $this>
     */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MenuBlockType::class,
            'position' => 'integer',
        ];
    }
}
