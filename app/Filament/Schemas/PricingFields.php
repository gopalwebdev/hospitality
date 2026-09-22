<?php

namespace App\Filament\Schemas;

use App\Enums\Currency;
use App\Enums\ItemAvailability;
use App\Models\TaxCode;
use App\Models\Tenant;
use App\Models\TenantSetting;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;

/**
 * The inputs behind a price: what it costs, what it used to, its GST rate, and how many one order may hold.
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
    public static function originalPrice(Currency $currency): TextInput
    {
        return TextInput::make('original_price')
            ->label(__('panel.items.original_price'))
            ->numeric()
            ->minValue(0)
            ->maxValue(99999)
            ->step(0.01)
            ->prefix($currency->symbol())
            ->gt('price')
            ->validationMessages(['gt' => __('panel.items.original_price_invalid')]);
    }

    /**
     * The GST rate a line carries, shown but **not typed**.
     *
     * It is filled by `taxCodePicker()` above it and disabled, on the project
     * owner's instruction: a rate is chosen by naming what is being sold, not
     * by typing a number from memory. A rate the catalogue does not offer is
     * added to the catalogue, which is a page a tenant owns
     * (`.ai/rules/tax-codes.md`).
     *
     * `dehydrated()` is not optional here — Filament leaves a disabled field
     * out of the save by default, which would blank the rate on every edit.
     *
     * There is deliberately **no placeholder**. It used to show the tenant's
     * own rate, so an empty box read "18" and looked filled in; the project
     * owner asked for it gone.
     */
    public static function taxRatePercentage(): TextInput
    {
        return TextInput::make('tax_rate_percentage')
            ->label(__('panel.items.tax_rate'))
            ->numeric()
            ->minValue(0)
            ->maxValue(100)
            ->step(0.01)
            ->suffix('%')
            ->disabled()
            ->dehydrated();
    }

    /**
     * The HSN or SAC code this line carries on a tax invoice.
     *
     * One field for both, as an invoice and GSTR-1 have one: HSN numbers goods
     * and SAC numbers services, and this menu carries both — a bottle of water
     * is goods, a bedsheet change is a service. Filled and disabled for the
     * same reason as the rate above.
     */
    public static function hsnSacCode(): TextInput
    {
        return TextInput::make('hsn_sac_code')
            ->label(__('panel.items.hsn_sac_code'))
            ->maxLength(8)
            ->disabled()
            ->dehydrated();
    }

    /**
     * The one control that sets a line's GST: pick what is being sold.
     *
     * Picking copies the code's rate and number onto the record and lets go —
     * the two fields below hold what was copied, and correcting the catalogue
     * later never reprices anything already filled in (`App\Models\TaxCode`).
     * Clearing it clears both, which is how a line goes back to the tenant's
     * own rate.
     */
    public static function taxCodePicker(): Select
    {
        return Select::make('tax_code_id')
            ->label(__('panel.tax_codes.picker'))
            ->placeholder(__('panel.tax_codes.picker_placeholder'))
            ->options(fn (): array => self::taxCodeOptions())
            ->searchable()
            ->native(false)
            // menu_items has no column for it: it only fills the two below.
            ->dehydrated(false)
            ->live()
            ->afterStateUpdated(self::applyTaxCode(...))
            // An edit opens on the code the record was filled from, worked out
            // from what it stored rather than from a column nothing keeps.
            ->formatStateUsing(fn (mixed $state, ?Model $record): ?int => $record instanceof Model
                ? self::taxCodeIdFor($record->getAttribute('hsn_sac_code'), $record->getAttribute('tax_rate'))
                : null);
    }

    /**
     * Copy a picked code onto the fields below it, or clear them when it is cleared.
     */
    public static function applyTaxCode(mixed $state, Set $set): void
    {
        $taxCode = blank($state) ? null : TaxCode::query()->find((int) $state);

        $set('tax_rate_percentage', $taxCode instanceof TaxCode ? self::toPercentage($taxCode->tax_rate) : null);
        $set('hsn_sac_code', $taxCode?->code);
    }

    /**
     * The codes this tenant may file something under: the catalogue, and its own.
     *
     * @return array<int, string>
     */
    public static function taxCodeOptions(): array
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Tenant) {
            return [];
        }

        // once(): Filament asks a select for its options more than once while
        // it builds and validates one form.
        return once(fn (): array => TaxCode::query()
            ->availableTo($tenant)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (TaxCode $taxCode): array => [
                $taxCode->getKey() => sprintf('%s · %s — %s', $taxCode->code, self::formatRate($taxCode->tax_rate), $taxCode->description),
            ])
            ->all());
    }

    /**
     * The code a stored number-and-rate pair came from, for a form being filled.
     */
    public static function taxCodeIdFor(?string $code, ?int $rate): ?int
    {
        if (blank($code) || $rate === null) {
            return null;
        }

        $tenant = Filament::getTenant();

        if (! $tenant instanceof Tenant) {
            return null;
        }

        return TaxCode::query()
            ->availableTo($tenant)
            ->where('code', $code)
            ->where('tax_rate', $rate)
            ->value('id');
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
     * The most of it one order may hold, counted across every basket line it is on.
     *
     * A feather pillow and a memory foam one are two towards a maximum of two. A
     * blank maximum is no limit, stored as null rather than as a limit of nothing.
     */
    public static function maxPerOrder(): TextInput
    {
        return TextInput::make('max_per_order')
            ->label(__('panel.items.max_per_order'))
            ->integer()
            ->minValue(1)
            ->maxValue(99)
            ->placeholder(__('panel.items.no_limit'))
            ->dehydrateStateUsing(fn (mixed $state): ?int => blank($state) ? null : (int) $state);
    }

    /**
     * Turn the typed values into what gets stored.
     *
     * The field and the column share a name and differ only in unit — a price
     * is typed in rupees and stored in paise — so each is converted **in
     * place**. Only `tax_rate_percentage`, which has no column of its own, is
     * unset; unsetting the others here would throw away what was just worked
     * out. That is not hypothetical: it is what dropping the `_minor_units`
     * suffix from the columns did before this comment was written.
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

        $data['price'] = $currency->toMinorUnits($data['price'] ?? 0);

        $data['original_price'] = blank($data['original_price'] ?? null)
            ? null
            : $currency->toMinorUnits($data['original_price']);

        if (array_key_exists('tax_rate_percentage', $data)) {
            $data['tax_rate'] = blank($data['tax_rate_percentage'])
                ? null
                : self::toBasisPoints($data['tax_rate_percentage']);
        }

        unset($data['tax_rate_percentage']);

        return $data;
    }

    /**
     * Turn the stored values back into what the form edits.
     *
     * The reverse of store(), and in place for the same reason.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fill(array $data, ?Currency $currency = null): array
    {
        $currency ??= self::currency();

        $data['price'] = $currency->toMajorUnits((int) ($data['price'] ?? 0));

        $data['original_price'] = blank($data['original_price'] ?? null)
            ? null
            : $currency->toMajorUnits((int) $data['original_price']);

        $data['tax_rate_percentage'] = blank($data['tax_rate'] ?? null)
            ? null
            : self::toPercentage((int) $data['tax_rate']);

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
     */
    public static function formatRate(int $basisPoints): string
    {
        return self::formattedPercentage($basisPoints).'%';
    }

    /**
     * The bare number a rate reads as, with no percent sign: 500 becomes "5", 1250 "12.5".
     *
     * Trailing zeros are trimmed, so a whole-number rate does not read "5.00".
     * Shared by `formatRate()` and by a placeholder that sits beside a field
     * which already carries its own '%' suffix.
     */
    private static function formattedPercentage(int $basisPoints): string
    {
        return rtrim(rtrim(number_format(self::toPercentage($basisPoints), 2), '0'), '.');
    }

    /**
     * The currency the tenant in this panel prices in.
     */
    public static function currency(): Currency
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Tenant ? $tenant->currency() : Currency::IndianRupee;
    }
}
