<?php

namespace App\Observers;

use App\Actions\Tenants\InheritParentTenant;
use App\Models\Location;
use LogicException;

/**
 * Keep a location at most two levels deep, under a Zone only — the
 * MenuCategoryObserver shape, self-referencing instead of through a parent
 * table.
 *
 * Every CHECK constraint was dropped from the schema (.ai/rules/migrations.md),
 * so this is the only guard against: a location being its own parent; a
 * parent that itself has a parent (two levels, no more); a parent on
 * another tenant; a parent whose kind cannot hold children (not a Zone); and
 * a Zone being given a parent at all.
 */
class LocationObserver
{
    /**
     * Take the parent's tenant, and keep a location one level deep under a
     * Zone.
     */
    public function saving(Location $location): void
    {
        app(InheritParentTenant::class)($location, Location::class, 'parent_id');

        // Rearranging a list saves every row; only a parent or a kind
        // actually changing needs asking about — kind matters here too,
        // because a location turning into a Zone while its old parent_id
        // is left untouched is exactly "a Zone given a parent".
        if (! $location->isDirty(['parent_id', 'kind'])) {
            return;
        }

        throw_if(
            $location->kind->canHoldChildren() && filled($location->parent_id),
            LogicException::class,
            'A zone cannot itself have a parent.',
        );

        if (blank($location->parent_id)) {
            return;
        }

        throw_if(
            (int) $location->parent_id === (int) $location->getKey(),
            LogicException::class,
            'A location cannot be its own parent.',
        );

        $parent = Location::query()
            ->withoutGlobalScopes()
            ->whereKey($location->parent_id)
            ->first(['id', 'kind', 'parent_id']);

        throw_if(
            $parent?->parent_id !== null,
            LogicException::class,
            'A location is two levels deep: a zone cannot sit under another location.',
        );

        throw_unless(
            $parent?->kind->canHoldChildren(),
            LogicException::class,
            'A location\'s parent has to be a zone.',
        );
    }
}
