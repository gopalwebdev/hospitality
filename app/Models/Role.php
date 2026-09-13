<?php

namespace App\Models;

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\Concerns\ReadsLoadedCounts;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use LogicException;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * A role, as stored.
 *
 * App\Enums\Role declares the roles this application ships with, and the
 * RolesAndPermissionsSeeder writes them here. A super admin may add further
 * roles from the product team panel; those have no enum case and are the ones
 * this class calls custom.
 *
 * config/permission.php points Spatie at this application's own models, so the
 * permissions relation really does hold App\Models\Permission. Saying so here
 * overrides the parent's annotation, which names Spatie's class and would
 * otherwise hide App\Models\Permission::group() from static analysis.
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
     * Keep the roles the application declares intact, and keep a role in use
     * from disappearing out from under whatever depends on it.
     *
     * These are registered in booting() rather than booted() on purpose.
     * Spatie's HasPermissions trait registers its own `deleting` listener that
     * detaches the role's users and permissions, and trait boot methods run
     * between the two — so a guard in booted() would be asked its question
     * after the links it checks had already been cut, and could never fire.
     *
     * The panel never offers any of these moves, so reaching them means code
     * has gone around the resource.
     */
    protected static function booting(): void
    {
        static::updating(static function (self $role): void {
            throw_if(
                $role->isDirty('name') && RoleEnum::tryFrom((string) $role->getOriginal('name')) instanceof RoleEnum,
                LogicException::class,
                'A built-in role may not be renamed.',
            );
        });

        static::deleting(static function (self $role): void {
            throw_if($role->isBuiltIn(), LogicException::class, 'A built-in role may not be deleted.');

            // Deleting a role someone holds revokes everything it granted them
            // without saying so, so the people come off it first.
            //
            // These ask the database rather than any count the model happens to
            // be carrying: undeletableReason() may answer from a count loaded
            // when the page rendered, which is right for deciding what to show
            // and wrong for deciding what to destroy.
            throw_if($role->users()->exists(), LogicException::class, 'A role held by a user may not be deleted.');

            // And a role is only ever deleted empty, so that what it granted is
            // spelt out on the way past rather than disappearing with it.
            throw_if($role->permissions()->exists(), LogicException::class, 'A role holding permissions may not be deleted.');
        });
    }

    /**
     * Whether this role is one the application itself declares.
     *
     * A built-in role is referenced by name from code (Role::Admin->value),
     * and the seeder owns its permissions, so it may not be renamed, deleted,
     * or have its permissions edited from the panel.
     */
    public function isBuiltIn(): bool
    {
        return RoleEnum::tryFrom($this->name) instanceof RoleEnum;
    }

    /**
     * The enum case this role stores, if it is a built-in one.
     */
    public function toEnum(): ?RoleEnum
    {
        return RoleEnum::tryFrom($this->name);
    }

    /**
     * Whether anything still depends on this role.
     *
     * Both halves of the link count: someone holding the role, and a permission
     * the role holds. A role in use may not be deleted, so emptying it is a
     * deliberate step rather than a side effect of removing it.
     */
    public function isInUse(): bool
    {
        return $this->holderCount() > 0 || $this->grantedPermissionCount() > 0;
    }

    /**
     * Why this role may not be deleted, or null when it may be.
     *
     * The resource shows this and the deleting hook throws it, so the panel and
     * the model never disagree about the reason.
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
     * How many accounts hold this role.
     *
     * Prefers what the page already loaded, so a table asks once for every row
     * rather than once per row. The deleting hook deliberately does not use
     * this — see booted above.
     */
    private function holderCount(): int
    {
        return $this->loadedCount('users_count') ?? $this->users()->count();
    }

    /**
     * How many permissions this role grants.
     */
    private function grantedPermissionCount(): int
    {
        return $this->loadedCount('permissions_count')
            ?? ($this->relationLoaded('permissions') ? $this->permissions->count() : $this->permissions()->count());
    }

    /**
     * Limit the query to roles a tenant may hand out.
     *
     * A role carrying any product team permission is withheld: tenant panels
     * assign roles, and assigning one of these would let a tenant admin
     * mint the product team.
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
}
