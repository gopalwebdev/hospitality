<?php

namespace App\Filament\Forms\Components;

use Filament\Forms\Components\Concerns\HasPlaceholder;
use Filament\Forms\Components\Field;

/**
 * A time of day picked from hour, minute and AM/PM columns.
 *
 * Filament's own picker drew three small number boxes, and the browser's native
 * input looked different in every browser, so the project owner asked for a
 * clock-style picker. Its state is "HH:MM", which a `time` column takes as it is.
 */
class ClockTimePicker extends Field
{
    use HasPlaceholder;

    #[\Override]
    protected string $view = 'filament.forms.components.clock-time-picker';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        // A time column hands back seconds as well; the picker is hours and minutes.
        $this->afterStateHydrated(static function (ClockTimePicker $component, mixed $state): void {
            $component->state(is_string($state) && $state !== '' ? substr($state, 0, 5) : null);
        });
    }
}
