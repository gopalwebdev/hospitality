<?php

namespace App\Models;

use App\Enums\CountryCallingCode;
use App\Enums\Currency;
use App\Enums\Role as RoleEnum;
use Carbon\CarbonImmutable;
use Database\Factories\RestaurantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A single restaurant, which is also the tenant boundary.
 *
 * The slug doubles as the subdomain, so `t1` is served at
 * t1.restaurant-app.com and administered at t1.restaurant-app.com/dashboard.
 *
 * The address and contact details are the platform's record of the business,
 * entered by a super admin when the restaurant is onboarded. What guests see
 * on the storefront lives in RestaurantSetting instead, and the restaurant
 * edits that itself.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $address
 * @property string $pincode
 * @property string|null $email
 * @property CountryCallingCode $phone_country_code
 * @property string $phone
 * @property CountryCallingCode|null $secondary_phone_country_code
 * @property string|null $secondary_phone
 * @property bool $is_active
 * @property int $max_admins
 * @property int $max_staff
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['slug', 'name', 'address', 'pincode', 'email', 'phone_country_code', 'phone', 'secondary_phone_country_code', 'secondary_phone', 'is_active', 'max_admins', 'max_staff'])]
class Restaurant extends Model
{
    /** @use HasFactory<RestaurantFactory> */
    use HasFactory;

    /**
     * Bind restaurants by slug so route and tenant resolution share one key.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * The staff and administrators attached to this restaurant.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        // Every foreign key to a restaurant is named tenant_id rather than the
        // restaurant_id Laravel would infer from this model, so each of these
        // relationships names its own key. One word for the tenant boundary,
        // whichever table is carrying it — see .ai/rules/models.md.
        return $this->belongsToMany(User::class, 'restaurant_user', 'tenant_id', 'user_id')
            ->withTimestamps();
    }

    /**
     * How this restaurant is configured.
     *
     * @return HasOne<RestaurantSetting, $this>
     */
    public function settings(): HasOne
    {
        return $this->hasOne(RestaurantSetting::class, 'tenant_id');
    }

    /**
     * The menus this restaurant serves.
     *
     * @return HasMany<Menu, $this>
     */
    public function menus(): HasMany
    {
        return $this->hasMany(Menu::class, 'tenant_id');
    }

    /**
     * The rows of the home screen a guest lands on after scanning a table's
     * QR code, each holding its own tiles.
     *
     * @return HasMany<HomeRow, $this>
     */
    public function homeRows(): HasMany
    {
        return $this->hasMany(HomeRow::class, 'tenant_id');
    }

    /**
     * Every tile on this restaurant's home screen, across all of its rows.
     *
     * @return HasMany<HomeTile, $this>
     */
    public function homeTiles(): HasMany
    {
        return $this->hasMany(HomeTile::class, 'tenant_id');
    }

    /**
     * Limit the query to restaurants that are open for business.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * How many accounts may hold this role on this restaurant's roster.
     *
     * Null for a role this restaurant does not cap — only Admin and Staff are
     * bounded for now. A super admin sets both limits per restaurant from
     * RestaurantForm; config/restaurants.php only supplies what a newly
     * created restaurant starts with.
     */
    public function roleLimit(RoleEnum $role): ?int
    {
        return match ($role) {
            RoleEnum::Admin => $this->max_admins,
            RoleEnum::Staff => $this->max_staff,
            RoleEnum::Guest => null,
        };
    }

    /**
     * How many accounts holding this role are on this restaurant's roster
     * right now.
     *
     * Roles are held per account, not per restaurant (see
     * .ai/rules/restaurants.md), so this counts roster members who happen to
     * hold the role — someone staffing this restaurant and another still
     * counts once here, against this restaurant's own limit. Pass the account
     * a grant is being considered for as $excluding so it never counts
     * against its own limit.
     */
    public function roleHolderCount(RoleEnum $role, ?User $excluding = null): int
    {
        // Read into a local before the closure: `when()`'s condition and its
        // callback are evaluated separately, so the null check outside does
        // not reach inside — to a reader or to static analysis.
        $excludedKey = ($excluding instanceof User && $excluding->exists)
            ? $excluding->getKey()
            : null;

        return $this->users()
            ->role($role->value)
            ->when(
                $excludedKey !== null,
                fn (Builder $query): Builder => $query->whereKeyNot($excludedKey),
            )
            ->count();
    }

    /**
     * This restaurant's settings, read once and remembered on the model.
     *
     * Currency, tax and whether orders are open are each asked for several
     * times while one page renders — by the guest middleware, a controller, and
     * every price on a panel table — and each used to issue its own query for
     * its own column. Loading the row once and keeping it as the `settings`
     * relation means the first question pays for all of them, and a list that
     * eager loaded `settings` pays nothing. It is never a lazy load, so it is
     * safe on a model that came out of a collection.
     */
    public function resolvedSettings(): ?RestaurantSetting
    {
        if (! $this->relationLoaded('settings')) {
            $this->setRelation(
                'settings',
                RestaurantSetting::query()->where('tenant_id', $this->getKey())->first(),
            );
        }

        $settings = $this->getRelation('settings');

        return $settings instanceof RestaurantSetting ? $settings : null;
    }

    /**
     * The currency this restaurant prices in.
     *
     * Every price on a menu shares one, so resolve it once and pass it down
     * rather than asking per dish.
     */
    public function currency(): Currency
    {
        return $this->resolvedSettings()->currency ?? Currency::IndianRupee;
    }

    /**
     * The GST rate this restaurant charges on anything that names no rate.
     */
    public function taxRateBasisPoints(): int
    {
        return $this->resolvedSettings()?->taxRateBasisPoints()
            ?? RestaurantSetting::DEFAULT_TAX_RATE_BASIS_POINTS;
    }

    /**
     * Whether this restaurant is taking new orders right now.
     */
    public function isAcceptingOrders(): bool
    {
        return $this->resolvedSettings()->accepts_orders ?? false;
    }

    /**
     * Where this restaurant's admins and staff sign in: /login on its subdomain.
     *
     * That address sends someone signed out to the panel's sign-in page and
     * someone signed in to /dashboard — see PanelSignInController.
     */
    public function signInUrl(): string
    {
        return route('restaurant.login', ['restaurant' => $this->slug]);
    }

    /**
     * The phone number as it is dialled, calling code and all.
     *
     * The two halves are stored apart so each column holds one fact; putting
     * them back together is a display concern and lives here.
     */
    public function dialablePhone(): string
    {
        return $this->phone_country_code->dialPrefix().' '.$this->phone;
    }

    /**
     * The secondary phone number as it is dialled, where there is one.
     */
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
            'phone_country_code' => CountryCallingCode::class,
            'secondary_phone_country_code' => CountryCallingCode::class,
            'is_active' => 'boolean',
        ];
    }
}
