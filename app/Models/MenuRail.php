<?php

namespace App\Models;

use App\Enums\MenuRailType;
use App\Observers\MenuRailObserver;
use Carbon\CarbonImmutable;
use Database\Factories\MenuRailFactory;
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
 * @property MenuRailType $type
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['menu_id', 'type', 'position'])]
#[ObservedBy([MenuRailObserver::class])]
class MenuRail extends Model
{
    /** @use HasFactory<MenuRailFactory> */
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
            'type' => MenuRailType::class,
            'position' => 'integer',
        ];
    }
}
