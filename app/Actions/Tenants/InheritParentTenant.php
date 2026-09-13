<?php

namespace App\Actions\Tenants;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Keep a tenant-owned row on its parent's tenant.
 *
 * A row with no tenant takes its parent's, and a row whose parent belongs to
 * another tenant is refused. The schema has no composite keys to say this, so
 * every observer of a child row asks here before the row is saved.
 */
class InheritParentTenant
{
    /**
     * @param  class-string<Model>  $parent
     *
     * @throws LogicException when the parent belongs to another tenant
     */
    public function __invoke(Model $child, string $parent, string $foreignKey): void
    {
        // A saved row whose parent and tenant are both unchanged has nothing new to
        // ask — rearranging a list saves every row, and may not have fetched either.
        if ($child->exists && ! $child->isDirty([$foreignKey, 'tenant_id'])) {
            return;
        }

        $parentId = $child->getAttribute($foreignKey);

        if (blank($parentId)) {
            return;
        }

        $tenantId = $child->getAttribute('tenant_id');

        $parentTenantId = $parent::query()->withoutGlobalScopes()->whereKey($parentId)->value('tenant_id');

        if (blank($tenantId)) {
            $child->setAttribute('tenant_id', $parentTenantId);

            return;
        }

        throw_if(
            (int) $parentTenantId !== (int) $tenantId,
            LogicException::class,
            sprintf('A %s cannot belong to another tenant\'s %s.', class_basename($child), class_basename($parent)),
        );
    }
}
