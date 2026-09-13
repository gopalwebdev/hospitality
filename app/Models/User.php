<?php

namespace App\Models;

use App\Enums\FilamentPanel;
use App\Observers\UserObserver;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property CarbonImmutable|null $email_verified_at
 * @property int|null $tenant_id
 * @property bool $is_admin
 * @property string|null $remember_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'email', 'tenant_id', 'is_admin'])]
#[Hidden(['remember_token'])]
#[ObservedBy([UserObserver::class])]
class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Database defaults only land on insert; an unsaved user still has to answer under strict mode.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'tenant_id' => null,
        'is_admin' => false,
    ];

    /**
     * The tenant this account belongs to; null for the product team. It grants nothing.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Every tenant this account staffs.
     *
     * @return BelongsToMany<Tenant, $this>
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class)->withTimestamps();
    }

    /**
     * @return HasMany<OneTimePassword, $this>
     */
    public function oneTimePasswords(): HasMany
    {
        return $this->hasMany(OneTimePassword::class);
    }

    /**
     * Accounts hold no password. An empty string keeps AuthenticateSession from signing them out.
     */
    public function getAuthPassword(): string
    {
        return '';
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeWithEmail(Builder $query, string $email): void
    {
        $query->whereRaw('lower(email) = ?', [mb_strtolower($email)]);
    }

    /**
     * The product team: a column rather than a role, and the only thing that grants the platform.
     */
    public function isAdmin(): bool
    {
        return $this->is_admin;
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeAdmins(Builder $query): void
    {
        $query->where('is_admin', true);
    }

    /**
     * How a row is labelled, not what the account may do — isAdmin() answers that.
     */
    public function belongsToProductTeam(): bool
    {
        return $this->tenant_id === null;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return match (FilamentPanel::tryFrom($panel->getId())) {
            FilamentPanel::Platform => $this->isAdmin(),
            FilamentPanel::Tenant => $this->isAdmin() || $this->tenants()->exists(),
            default => false,
        };
    }

    /**
     * @return Collection<int, Tenant>
     */
    public function getTenants(Panel $panel): Collection
    {
        if ($this->isAdmin()) {
            // Filament asks for this more than once per request.
            return once(fn (): Collection => Tenant::query()->orderBy('name')->get());
        }

        return $this->tenants;
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->isAdmin() || $this->tenants()->whereKey($tenant)->exists();
    }

    /**
     * Roles are held per account, so someone on several rosters has them changed by the product team only.
     */
    public function staffsSeveralTenants(): bool
    {
        return once(fn (): bool => $this->tenants()->count() > 1);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'tenant_id' => 'integer',
            'is_admin' => 'boolean',
        ];
    }
}
