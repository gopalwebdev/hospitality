<?php

namespace App\Filament\Schemas;

use App\Enums\Currency;
use App\Enums\ItemAvailability;
use App\Models\Tenant;
use App\Models\TenantSetting;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

/**
 * The inputs behind a price: what it costs, what it used to, and its GST rate.
 *
 * An item and a combo are priced identically, so the fields and — more
 * importantly — the conversions in and out of storage live here once.
 *
 * Two conversions, both happening only here so each rounds exactly once:
 * money is typed in major units and stored as an integer count of minor ones,
 * and a tax rate is typed as a percentage and stored as basis points. Neither a
 * float price nor a float rate ever reaches the database.
 *
 * No helper text on any of these. The labels say what the fields are, and a
 * paragraph under every input is what made the item form a page and a half of
 * prose to fill in one line of prices.
 */
final class PricingFields
{
    /**
     * What a guest pays.
     */
    public static function price(Currency $currency): TextInput
    {
        return TextInput::make('price')
            ->label(__('panel.items.price'))
            ->required()
            ->numeric()
            ->minValue(0)
            ->maxValue(99999)
            ->step(0.01)
            ->prefix($currency->symbol());
    }

    /**
     * The higher price shown struck through beside it.
     *
     * Validated to be above the real price rather than merely different: a
     * price at or below what is charged advertises a discount that does not
     * exist, which is the one way this field can mislead a guest. Left blank
     * when the item is not on offer — a zero would be a price of nothing.
     */
    public static function compareAtPrice(Currency $currency): TextInput
    {
        return TextInput::make('compare_at_price')
            ->label(__('panel.items.compare_at_price'))
            ->numeric()
            ->minValue(0)
            ->maxValue(99999)
            ->step(0.01)
            ->prefix($currency->symbol())
            ->gt('price')
            ->validationMessages(['gt' => __('panel.items.compare_at_price_invalid')]);
    }

    /**
     * The GST rate, typed as the percentage an accountant quotes.
     *
     * A number rather than a list of slabs on purpose: India's GST 2.0 reform
     * of September 2025 restructured the slabs, and the next notification may
     * do so again — a hardcoded list is one notification from being wrong.
     *
     * The placeholder is the tenant's own rate, so leaving it empty visibly
     * means "whatever settings says" without a sentence explaining it.
     */
    public static function taxRatePercentage(int $tenantRateBasisPoints): TextInput
    {
        return TextInput::make('tax_rate_percentage')
            ->label(__('panel.items.tax_rate'))
            ->numeric()
            ->minValue(0)
            ->maxValue(100)
            ->step(0.01)
            ->suffix('%')
            ->placeholder(self::formatRate($tenantRateBasisPoints));
    }

    /**
     * The HSN or SAC code this line carries on a tax invoice.
     */
    public static function hsnCode(): TextInput
    {
        return TextInput::make('hsn_code')
            ->label(__('panel.items.hsn_code'))
            ->maxLength(8);
    }

    /**
     * Whether a guest may order this, and why not when they may not.
     */
    public static function availability(): Select
    {
        return Select::make('availability')
            ->label(__('panel.items.availability'))
            ->options(ItemAvailability::options())
            ->default(ItemAvailability::Available->value)
            ->required()
            ->native(false);
    }

    /**
     * Turn the typed values into what gets stored.
     *
     * A blank compare-at price and a blank rate are both stored as null rather
     * than zero: null means "not on offer" and "follow the tenant", where a
     * zero would mean a price of nothing and a tax rate of nothing.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function store(array $data, ?Currency $currency = null): array
    {
        $currency ??= self::currency();

        $data['price_minor_units'] = $currency->toMinorUnits($data['price'] ?? 0);

        $data['compare_at_price_minor_units'] = blank($data['compare_at_price'] ?? null)
            ? null
            : $currency->toMinorUnits($data['compare_at_price']);

        if (array_key_exists('tax_rate_percentage', $data)) {
            $data['tax_rate_basis_points'] = blank($data['tax_rate_percentage'])
                ? null
                : self::toBasisPoints($data['tax_rate_percentage']);
        }

        unset($data['price'], $data['compare_at_price'], $data['tax_rate_percentage']);

        return $data;
    }

    /**
     * Turn the stored values back into what the form edits.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fill(array $data, ?Currency $currency = null): array
    {
        $currency ??= self::currency();

        $data['price'] = $currency->toMajorUnits((int) ($data['price_minor_units'] ?? 0));

        $data['compare_at_price'] = blank($data['compare_at_price_minor_units'] ?? null)
            ? null
            : $currency->toMajorUnits((int) $data['compare_at_price_minor_units']);

        $data['tax_rate_percentage'] = blank($data['tax_rate_basis_points'] ?? null)
            ? null
            : self::toPercentage((int) $data['tax_rate_basis_points']);

        return $data;
    }

    /**
     * A typed percentage as the basis points that get stored: 5 becomes 500.
     *
     * The rounding happens here, once, so nothing downstream sees a float.
     */
    public static function toBasisPoints(float|int|string $percentage): int
    {
        return (int) round(((float) $percentage) * (TenantSetting::BASIS_POINTS_PER_WHOLE / 100));
    }

    /**
     * Stored basis points as the percentage a form edits: 500 becomes 5.0.
     */
    public static function toPercentage(int $basisPoints): float
    {
        return $basisPoints / (TenantSetting::BASIS_POINTS_PER_WHOLE / 100);
    }

    /**
     * Stored basis points as a rate to read: 500 becomes "5%", 1250 "12.5%".
     *
     * Trailing zeros are trimmed, so a whole-number rate does not read "5.00%".
     */
    public static function formatRate(int $basisPoints): string
    {
        return rtrim(rtrim(number_format(self::toPercentage($basisPoints), 2), '0'), '.').'%';
    }

    /**
     * The currency the tenant in this panel prices in.
     */
    public static function currency(): Currency
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Tenant ? $tenant->currency() : Currency::IndianRupee;
    }

    /**
     * The GST rate the tenant in this panel charges by default.
     */
    public static function tenantTaxRateBasisPoints(): int
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Tenant
            ? $tenant->taxRateBasisPoints()
            : TenantSetting::DEFAULT_TAX_RATE_BASIS_POINTS;
    }
}
