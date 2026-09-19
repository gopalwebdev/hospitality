<?php

namespace App\Models\Concerns;

/**
 * A model with times of day stored in `time` columns.
 *
 * Postgres hands a `time` column back as "09:00:00" and a form's clock picker
 * sets "09:00", so both shapes reach a model. Padding to a fixed width is what
 * lets two of them be compared as plain strings, which is how every window here
 * is answered — no timezone arithmetic, because a tenant's hours are wall-clock
 * hours in the application's own zone (`.ai/rules/config.md`).
 */
trait ReadsClockTimes
{
    /**
     * HH:MM:SS, padded to a fixed width so two times compare as strings.
     */
    protected function normalisedTime(string $time): string
    {
        return substr($time.':00:00', 0, 8);
    }

    /**
     * The same time as a clock reads it: HH:MM.
     */
    protected function clockReading(string $time): string
    {
        return substr($this->normalisedTime($time), 0, 5);
    }
}
