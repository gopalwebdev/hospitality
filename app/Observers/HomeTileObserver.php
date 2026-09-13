<?php

namespace App\Observers;

use App\Actions\Tenants\InheritParentTenant;
use App\Enums\HomeTileAction;
use App\Models\HomeRow;
use App\Models\HomeTile;
use App\Models\Menu;
use LogicException;

class HomeTileObserver
{
    /**
     * Keep the tile's destination in step with its action, and the tile on its
     * row's tenant — its menu, when it opens one, included.
     *
     * The columns an action does not use are cleared first, which is what lets
     * a tile change action at all.
     */
    public function saving(HomeTile $tile): void
    {
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

        $inheritParentTenant = app(InheritParentTenant::class);

        $inheritParentTenant($tile, HomeRow::class, 'home_row_id');
        $inheritParentTenant($tile, Menu::class, 'menu_id');
    }
}
