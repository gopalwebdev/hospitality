<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\OneTimePasswordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A sign-in code issued to one user. Only its hash is kept.
 *
 * @property int $id
 * @property int $user_id
 * @property string $code_hash
 * @property int $attempts
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $consumed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['code_hash', 'expires_at'])]
#[Hidden(['code_hash'])]
class OneTimePassword extends Model
{
    /** @use HasFactory<OneTimePasswordFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function hasBeenConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function hasExhaustedAttempts(): bool
    {
        return $this->attempts >= (int) config('otp.max_attempts');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('consumed_at')->where('expires_at', '>', now());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
