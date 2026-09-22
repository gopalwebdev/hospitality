<?php

namespace App\Actions\Baskets;

use App\Models\TenantSetting;

/**
 * One amount's GST, split into the two parts an invoice has to show separately.
 *
 * Rule 46 of the CGST Rules makes a tax invoice state the rate **and** the
 * amount of CGST and SGST (or UTGST) each on its own, so the split is decided
 * once, here, at the moment a line is priced, and stored on the order beside
 * the money it describes. Nothing re-derives it later.
 *
 * **There is no IGST here, and that is a scope decision, not an oversight.**
 * Everything this application sells is consumed where it is served, and the
 * place of supply for lodging and for anything eaten or used on the premises is
 * the premises themselves (IGST Act, s. 12(3)) — so a guest from another state
 * is still an intra-state supply. An inter-state case would need its own
 * columns, its own rows on a bill and a third branch through every piece of
 * arithmetic below; it is not worth carrying for a supply that cannot happen.
 *
 * Whether the state's half is called SGST or UTGST is wording, not money: it
 * rides these same columns, and `tenant_settings.is_union_territory` is what
 * a bill reads to name it.
 *
 * How the arithmetic is arranged, and why:
 *
 * - **The rate splits first, in basis points**, `intdiv($rate, 2)` and the
 *   remainder, so the two halves always add to the rate exactly even when it is
 *   odd. Every real GST slab is even in basis points (5% is 500, 18% is 1800),
 *   so the remainder only matters if a tenant types something like 1.25%.
 * - **Each half is worked out from its own rate**, and the tax charged is what
 *   the two come to. They are separate levies on one taxable value — s. 9 of
 *   the CGST Act and s. 7 of the UTGST Act — so equal rates have to produce
 *   equal amounts. Working the whole rate out first and handing the state
 *   whatever was left of it did not: ₹50.00 carrying 18% came to ₹7.63, which
 *   is odd, and the bill read "CGST 9% ₹3.81" beside "UTGST 9% ₹3.82" — a
 *   figure neither 9% nor the taxable value beside it produces.
 * - **An inclusive price divides by `10000 + rate`**, the full rate, because
 *   that is what the price already carries; only the numerator is the half.
 *
 * The rate being split is **the rate of the thing being taxed** — an item's own
 * `tax_rate` where it has one — never the tenant's two settings columns. Those
 * are the tenant's default, and halving whatever rate applies is what lets a 5%
 * item and an 18% item sit on one bill.
 *
 * Amounts are integers in the currency's minor unit throughout, like all money
 * here (`.ai/rules/migrations.md`). Nothing in this class returns a float.
 */
final readonly class GstSplit
{
    public function __construct(
        public int $cgstRate,
        public int $cgst,
        public int $sgstRate,
        public int $sgst,
    ) {}

    /**
     * No tax at all, for a basket with nothing in it or a line priced at nothing.
     */
    public static function none(): self
    {
        return new self(0, 0, 0, 0);
    }

    /**
     * The GST on one amount at one rate.
     *
     * `$pricesIncludeTax` says the amount already carries it, in which case
     * this is the share inside the price rather than something to add on top.
     */
    public static function on(int $amount, int $rate, bool $pricesIncludeTax): self
    {
        $centre = intdiv($rate, 2);
        $state = $rate - $centre;

        // Each half from its own rate, never one figure halved: two levies on
        // the same value, so an equal rate is an equal amount. What they come
        // to is the tax charged, which is what total() adds back up.
        return new self(
            $centre,
            self::taxOn($amount, $centre, $rate, $pricesIncludeTax),
            $state,
            self::taxOn($amount, $state, $rate, $pricesIncludeTax),
        );
    }

    /**
     * This split and another, added part by part.
     *
     * A bill is priced part by part and rounded once per part, so the totals on
     * an order are these sums rather than a second calculation over the whole.
     */
    public function plus(self $other): self
    {
        return new self(
            max($this->cgstRate, $other->cgstRate),
            $this->cgst + $other->cgst,
            max($this->sgstRate, $other->sgstRate),
            $this->sgst + $other->sgst,
        );
    }

    /**
     * What the parts come to in all — the one number a total has always carried.
     */
    public function total(): int
    {
        return $this->cgst + $this->sgst;
    }

    /**
     * The whole rate this was levied at, halves added back together.
     */
    public function rate(): int
    {
        return $this->cgstRate + $this->sgstRate;
    }

    /**
     * The columns an order or an order line stores this in.
     *
     * @return array{cgst_rate: int, cgst: int, sgst_rate: int, sgst: int}
     */
    public function columns(): array
    {
        return [
            'cgst_rate' => $this->cgstRate,
            'cgst' => $this->cgst,
            'sgst_rate' => $this->sgstRate,
            'sgst' => $this->sgst,
        ];
    }

    /**
     * The tax at `$rate` on an amount whose price carries `$wholeRate`.
     *
     * The two rates are the same for a whole line's tax and differ only when
     * one half is being worked out of a tax-inclusive price: the numerator is
     * the half, the denominator is what the price actually carries.
     */
    private static function taxOn(int $amount, int $rate, int $wholeRate, bool $pricesIncludeTax): int
    {
        if ($amount === 0 || $rate === 0) {
            return 0;
        }

        $whole = TenantSetting::BASIS_POINTS_PER_WHOLE;

        return $pricesIncludeTax
            ? (int) round($amount * $rate / ($whole + $wholeRate))
            : (int) round($amount * $rate / $whole);
    }
}
