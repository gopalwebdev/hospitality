<?php

namespace App\Models\Concerns;

use App\Enums\Currency;
use App\Models\Tenant;
use App\Models\TenantSetting;

/**
 * Something a guest can have off a menu: an item, or a combo of them.
 *
 * Both carry the same four things — a price in minor units, an optional higher
 * price shown struck through beside it, an optional GST rate of their own, and
 * a currency that belongs to the tenant rather than to them. This is where
 * that behaviour lives once, so the two models cannot drift.
 *
 * Using models must have `price_minor_units`, `compare_at_price_minor_units`
 * and `tax_rate_basis_points` columns, and a `tenant_id`.
 *
 * Every reader takes an optional override, and lists should pass one.
 * Resolving the currency or the tax rate per row is a query per row that
 * answers the same thing for every one of them — every item on a menu shares
 * one tenant. See .ai/rules/models.md.
 */
trait IsPricedOnAMenu
{
    /**
     * The currency this is priced in.
     *
     * Deliberately never reaches through $this->tenant: that is a lazy
     * load, which Model::shouldBeStrict() turns into an exception outside
     * production and which is an N+1 down a list of items inside it.
     */
    public function currency(): Currency
    {
        $tenant = $this->relationLoaded('tenant') ? $this->getRelation('tenant') : null;

        if ($tenant instanceof Tenant) {
            return $tenant->currency();
        }

        // value() on an Eloquent builder applies the model's cast, so this
        // comes back as the enum already. A tenant with no settings row
        // yet has no currency, and falls back to the default.
        $stored = TenantSetting::query()
            ->where('tenant_id', $this->tenant_id)
            ->value('currency');

        return $stored instanceof Currency ? $stored : Currency::IndianRupee;
    }

    /**
     * The GST rate this is taxed at, in basis points.
     *
     * A row of its own overrides, and null means "whatever the tenant
     * charges" — the answer for almost everything on a menu, so the rate is set
     * once in settings rather than on every item.
     *
     * Pass $tenantRate when rendering a list; every row shares it.
     */
    public function taxRateBasisPoints(?int $tenantRate = null): int
    {
        if ($this->tax_rate_basis_points !== null) {
            return $this->tax_rate_basis_points;
        }

        if ($tenantRate !== null) {
            return $tenantRate;
        }

        $tenant = $this->relationLoaded('tenant') ? $this->getRelation('tenant') : null;

        if ($tenant instanceof Tenant) {
            return $tenant->taxRateBasisPoints();
        }

        $stored = TenantSetting::query()
            ->where('tenant_id', $this->tenant_id)
            ->value('tax_rate_basis_points');

        return $stored === null
            ? TenantSetting::DEFAULT_TAX_RATE_BASIS_POINTS
            : (int) $stored;
    }

    /**
     * Whether this sets its own rate rather than following the tenant's.
     *
     * What the panel colours a badge on, so the exceptions stand out down a
     * long list of items that all follow the default.
     */
    public function overridesTaxRate(): bool
    {
        return $this->tax_rate_basis_points !== null;
    }

    /**
     * Whether a higher price is shown struck through beside the real one.
     */
    public function hasComparePrice(): bool
    {
        return $this->compare_at_price_minor_units !== null
            && $this->compare_at_price_minor_units > $this->price_minor_units;
    }

    /**
     * What is knocked off, in minor units, or zero when nothing is.
     */
    public function discountMinorUnits(): int
    {
        return $this->hasComparePrice()
            ? $this->compare_at_price_minor_units - $this->price_minor_units
            : 0;
    }

    /**
     * The price as money, in the tenant's own currency.
     *
     * For the Filament tables, which are server rendered. The guest app is sent
     * the integer and formats it itself — see .ai/rules/js.md.
     */
    public function formattedPrice(?Currency $currency = null): string
    {
        return ($currency ?? $this->currency())->format($this->price_minor_units);
    }

    /**
     * The struck-through price as money, or null when there is not one.
     */
    public function formattedComparePrice(?Currency $currency = null): ?string
    {
        $compareAt = $this->compare_at_price_minor_units;

        // Read into a local rather than checked through hasComparePrice(): the
        // two say the same thing, but only this makes the value non-null to a
        // reader and to static analysis.
        if ($compareAt === null || $compareAt <= $this->price_minor_units) {
            return null;
        }

        return ($currency ?? $this->currency())->format($compareAt);
    }
}
