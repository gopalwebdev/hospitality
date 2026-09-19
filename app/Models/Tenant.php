<?php

namespace App\Models;

use App\Enums\CountryCallingCode;
use App\Enums\Currency;
use App\Enums\Role as RoleEnum;
use App\Enums\TenantType;
use App\Enums\Weekday;
use Carbon\CarbonImmutable;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One business on the platform, and the boundary everything it owns is scoped
 * to. The slug is its subdomain: `t1` is served at t1.hospitality.com.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property TenantType $type
 * @property string $address
 * @property string $pincode
 * @property string|null $email
 * @property CountryCallingCode $phone_country_code
 * @property string $phone
 * @property CountryCallingCode|null $secondary_phone_country_code
 * @property string|null $secondary_phone
 * @property bool $is_active
 * @property int $max_owners
 * @property int $max_staff
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['slug', 'name', 'type', 'address', 'pincode', 'email', 'phone_country_code', 'phone', 'secondary_phone_country_code', 'secondary_phone', 'is_active', 'max_owners', 'max_staff'])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * @return HasOne<TenantSetting, $this>
     */
    public function settings(): HasOne
    {
        return $this->hasOne(TenantSetting::class);
    }

    /**
     * @return HasMany<Menu, $this>
     */
    public function menus(): HasMany
    {
        return $this->hasMany(Menu::class);
    }

    /**
     * @return HasMany<HomeRow, $this>
     */
    public function homeRows(): HasMany
    {
        return $this->hasMany(HomeRow::class);
    }

    /**
     * @return HasMany<HomeTile, $this>
     */
    public function homeTiles(): HasMany
    {
        return $this->hasMany(HomeTile::class);
    }

    /**
     * @return HasMany<Charge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }

    /**
     * @return HasMany<TenantOpeningHour, $this>
     */
    public function openingHours(): HasMany
    {
        return $this->hasMany(TenantOpeningHour::class);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * How many accounts may hold a role here, or null where the role is not capped.
     */
    public function roleLimit(RoleEnum $role): ?int
    {
        return match ($role) {
            RoleEnum::Owner => $this->max_owners,
            RoleEnum::Staff => $this->max_staff,
            RoleEnum::Guest => null,
        };
    }

    /**
     * How many accounts on this roster hold a role, never counting $excluding.
     */
    public function roleHolderCount(RoleEnum $role, ?User $excluding = null): int
    {
        $excludedKey = ($excluding instanceof User && $excluding->exists) ? $excluding->getKey() : null;

        return $this->users()
            ->role($role->value)
            ->when($excludedKey !== null, fn (Builder $query): Builder => $query->whereKeyNot($excludedKey))
            ->count();
    }

    /**
     * The settings row, read once and kept as the `settings` relation so it is
     * never a lazy load.
     */
    public function resolvedSettings(): ?TenantSetting
    {
        if (! $this->relationLoaded('settings')) {
            $this->setRelation('settings', TenantSetting::query()->where('tenant_id', $this->getKey())->first());
        }

        $settings = $this->getRelation('settings');

        return $settings instanceof TenantSetting ? $settings : null;
    }

    public function currency(): Currency
    {
        return $this->resolvedSettings()->currency ?? Currency::IndianRupee;
    }

    public function taxRateBasisPoints(): int
    {
        return $this->resolvedSettings()?->taxRateBasisPoints() ?? TenantSetting::DEFAULT_TAX_RATE_BASIS_POINTS;
    }

    /**
     * Whether the tenant's own rate is charged on everything, ignoring an item's own.
     */
    public function overridesItemTaxRates(): bool
    {
        return $this->resolvedSettings()?->overridesItemTaxRates() ?? false;
    }

    /**
     * The week's hours, read once and kept as the `openingHours` relation, keyed by day.
     *
     * Keyed rather than a list because every reader wants one named day, and a
     * lazy load per day is what `Model::shouldBeStrict()` throws on.
     *
     * @return Collection<string, TenantOpeningHour>
     */
    public function resolvedOpeningHours(): Collection
    {
        if (! $this->relationLoaded('openingHours')) {
            $this->setRelation('openingHours', TenantOpeningHour::query()->where('tenant_id', $this->getKey())->get());
        }

        /** @var Collection<int, TenantOpeningHour> $hours */
        $hours = $this->getRelation('openingHours');

        return $hours->keyBy(fn (TenantOpeningHour $day): string => $day->weekday->value);
    }

    /**
     * The hours kept on the day a moment falls on, or null where the tenant has set none.
     */
    public function hoursToday(?CarbonImmutable $moment = null): ?TenantOpeningHour
    {
        return $this->resolvedOpeningHours()->get(Weekday::on($moment ?? CarbonImmutable::now())->value);
    }

    /**
     * Whether the doors are open, which is what decides if an order may be placed.
     *
     * A tenant that has never set its hours keeps none, so it is always open:
     * no tenant is shut out of taking orders by a table nobody has filled in.
     * A window that runs past midnight belongs to the day it started on, so a
     * moment at one in the morning asks yesterday's row as well as today's.
     */
    public function isOpenAt(?CarbonImmutable $moment = null): bool
    {
        $moment ??= CarbonImmutable::now();
        $week = $this->resolvedOpeningHours();

        if ($week->isEmpty()) {
            return true;
        }

        $today = Weekday::on($moment);

        if ($week->get($today->value)?->isOpenAt($moment) === true) {
            return true;
        }

        return $week->get($today->previous()->value)?->runsPastMidnightInto($moment) === true;
    }

    /**
     * /login on this tenant's subdomain, which leads into its panel.
     */
    public function signInUrl(): string
    {
        return route('tenant.login', ['tenant' => $this->slug]);
    }

    public function dialablePhone(): string
    {
        return $this->phone_country_code->dialPrefix().' '.$this->phone;
    }

    public function dialableSecondaryPhone(): ?string
    {
        if (blank($this->secondary_phone) || ! $this->secondary_phone_country_code instanceof CountryCallingCode) {
            return null;
        }

        return $this->secondary_phone_country_code->dialPrefix().' '.$this->secondary_phone;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TenantType::class,
            'phone_country_code' => CountryCallingCode::class,
            'secondary_phone_country_code' => CountryCallingCode::class,
            'is_active' => 'boolean',
        ];
    }
}
