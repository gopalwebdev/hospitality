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
}
