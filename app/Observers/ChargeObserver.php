<?php

namespace App\Observers;

use App\Enums\ChargeCalculation;
use App\Models\Charge;
use LogicException;

class ChargeObserver
{
    /**
     * Keep a charge's number in the column its calculation reads, as the CHECK constraint does.
     *
     * The column a calculation does not use is cleared first, which is what lets
     * a charge change from a percentage to a fixed amount at all; the one it does
     * use has to be filled.
     */
    public function saving(Charge $charge): void
    {
        $required = $charge->calculation->valueColumn();

        foreach (ChargeCalculation::everyValueColumn() as $column) {
            if ($column !== $required) {
                $charge->{$column} = null;
            }
        }

        throw_if(
            $charge->{$required} === null,
            LogicException::class,
            sprintf('A %s charge needs a %s.', $charge->calculation->value, $required),
        );
    }

    /**
     * A charge on every menu keeps no list of menus.
     *
     * So a menu added later is covered without anyone ticking it, and a list
     * left over from when the charge was limited cannot quietly come back.
     * Cleared after the save, because a list chosen on the form is written
     * after the charge itself.
     */
    public function saved(Charge $charge): void
    {
        if ($charge->applies_to_all_menus) {
            $charge->menus()->detach();
        }
    }
}
