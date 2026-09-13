<?php

namespace App\Models;

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\Concerns\ReadsLoadedCounts;
use App\Observers\RoleObserver;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * A role, as stored. One with an App\Enums\Role case is built-in; the rest were added from the panel.
 *
 * @property int $id
 * @property string $name
 * @property string $guard_name
 * @property-read Collection<int, Permission> $permissions
 */
class Role extends SpatieRole
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    use ReadsLoadedCounts;

    /**
     * The observer is wired before Spatie's traits boot, so its guards run
     * before Spatie detaches the links they ask about. See RoleObserver.
     */
    protected static function booting(): void
    {
        static::updating([RoleObserver::class, 'updating']);
        static::deleting([RoleObserver::class, 'deleting']);
    }

    public function isBuiltIn(): bool
    {
        return RoleEnum::tryFrom($this->name) instanceof RoleEnum;
    }

    public function toEnum(): ?RoleEnum
    {
        return RoleEnum::tryFrom($this->name);
    }

    public function isInUse(): bool
    {
        return $this->holderCount() > 0 || $this->grantedPermissionCount() > 0;
    }

    /**
     * Why this role may not be deleted, or null when it may be.
     */
    public function undeletableReason(): ?string
    {
        return match (true) {
            $this->isBuiltIn() => 'This role is declared in code, so it may not be deleted. What it grants is still yours to change.',
            $this->holderCount() > 0 => 'This role is held by a user. Move everyone off it before deleting it.',
            $this->grantedPermissionCount() > 0 => 'This role still holds permissions. Clear them before deleting it.',
            default => null,
        };
    }

    /**
     * Roles a tenant panel may hand out: none carrying a product team permission.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeAssignableWithinTenant(Builder $query): void
    {
        $query->whereDoesntHave(
            'permissions',
            fn (Builder $permissions) => $permissions->whereIn('name', PermissionEnum::productTeamOnlyValues()),
        );
    }

    private function holderCount(): int
    {
        return $this->loadedCount('users_count') ?? $this->users()->count();
    }

    private function grantedPermissionCount(): int
    {
        return $this->loadedCount('permissions_count')
            ?? ($this->relationLoaded('permissions') ? $this->permissions->count() : $this->permissions()->count());
    }
}
