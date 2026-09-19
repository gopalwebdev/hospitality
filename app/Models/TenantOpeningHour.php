<?php

namespace App\Models;

use App\Enums\Weekday;
use App\Models\Concerns\ReadsClockTimes;
use Carbon\CarbonImmutable;
use Database\Factories\TenantOpeningHourFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * When one tenant's doors are open on one day of the week.
 *
 * A week is seven rows, and a day is either closed — the weekly holiday — or
 * open between two wall-clock times. A window may run past midnight (open at
 * six, closed at one); the half after midnight belongs to the day it started
 * on, which is why `Tenant::isOpenAt()` asks yesterday's row as well.
 *
 * @property int $id
 * @property int $tenant_id
 * @property-read Tenant $tenant
 * @property Weekday $weekday
 * @property bool $is_closed
 * @property string|null $opens_at
 * @property string|null $closes_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['weekday', 'is_closed', 'opens_at', 'closes_at'])]
class TenantOpeningHour extends Model
{
    /** @use HasFactory<TenantOpeningHourFactory> */
    use HasFactory;

    use ReadsClockTimes;

    /** @var array<string, mixed> */
    #[\Override]
    protected $attributes = [
        'is_closed' => false,
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Whether the doors are open at a time of day on this day itself.
     *
     * A window running past midnight counts only up to midnight here; the rest
     * of it is `runsPastMidnightInto()`, asked of the day before.
     */
    public function isOpenAt(CarbonImmutable $moment): bool
    {
        $window = $this->window();

        if ($window === null) {
            return false;
        }

        [$from, $until] = $window;
        $now = $moment->format('H:i:s');

        return $from <= $until
            ? $now >= $from && $now < $until
            : $now >= $from;
    }

    /**
     * Whether this day's window is still running after midnight, at a moment on the day after it.
     */
    public function runsPastMidnightInto(CarbonImmutable $moment): bool
    {
        $window = $this->window();

        if ($window === null) {
            return false;
        }

        [$from, $until] = $window;

        return $from > $until && $moment->format('H:i:s') < $until;
    }

    /**
     * When the doors open, as a clock reads it, or null on a day they do not.
     */
    public function opensAt(): ?string
    {
        return $this->is_closed || $this->opens_at === null ? null : $this->clockReading($this->opens_at);
    }

    /**
     * When they close, as a clock reads it, or null on a day they do not open.
     */
    public function closesAt(): ?string
    {
        return $this->is_closed || $this->closes_at === null ? null : $this->clockReading($this->closes_at);
    }

    /**
     * This day's window as two comparable times, or null while it is closed.
     *
     * @return array{0: string, 1: string}|null
     */
    private function window(): ?array
    {
        if ($this->is_closed || $this->opens_at === null || $this->closes_at === null) {
            return null;
        }

        return [$this->normalisedTime($this->opens_at), $this->normalisedTime($this->closes_at)];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekday' => Weekday::class,
            'is_closed' => 'boolean',
        ];
    }
}
