<?php

namespace App\Models;

use App\Enums\Permission as PermissionEnum;
use App\Enums\PermissionGroup;
use App\Models\Concerns\ReadsLoadedCounts;
use App\Observers\PermissionObserver;
use Database\Factories\PermissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * A permission, as stored. One with an App\Enums\Permission case is checked by code, so it is built-in.
 *
 * @property int $id
 * @property string $name
 * @property string $guard_name
 */
class Permission extends SpatiePermission
{
    /** @use HasFactory<PermissionFactory> */
    use HasFactory;

    use ReadsLoadedCounts;

    /**
     * Wired before Spatie's traits boot, for the same reason as Role::booting().
     */
    protected static function booting(): void
    {
        static::updating([PermissionObserver::class, 'updating']);
        static::deleting([PermissionObserver::class, 'deleting']);
    }

    public function isBuiltIn(): bool
    {
        return PermissionEnum::tryFrom($this->name) instanceof PermissionEnum;
    }

    public function isInUse(): bool
    {
        return $this->holderCount() > 0;
    }

    /**
     * Why this permission may not be deleted, or null when it may be.
     */
    public function undeletableReason(): ?string
    {
        return match (true) {
            $this->isBuiltIn() => 'This permission is declared in code, so it may not be deleted.',
            $this->isInUse() => 'This permission is held by a role. Take it off every role before deleting it.',
            default => null,
        };
    }

    public function group(): PermissionGroup
    {
        return PermissionGroup::forPermissionName($this->name);
    }

    public function label(): string
    {
        return PermissionEnum::tryFrom($this->name)?->label() ?? $this->name;
    }

    private function holderCount(): int
    {
        return $this->loadedCount('roles_count')
            ?? ($this->relationLoaded('roles') ? $this->roles->count() : $this->roles()->count());
    }
}
