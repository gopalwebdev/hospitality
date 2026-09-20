<?php

namespace App\Actions\Baskets;

use App\Enums\GstTreatment;
use App\Models\TenantSetting;
use LogicException;

/**
 * One amount's GST, split into the parts an invoice has to show separately.
 *
 * Rule 46 of the CGST Rules makes a tax invoice state the rate **and** the
 * amount of CGST and SGST (or UTGST, or IGST) each on its own. A single total
 * cannot be halved safely after the fact — ₹12.51 of tax is ₹6.255 a side, and
 * whichever way that is rounded the two halves still have to add back up to
 * exactly what was charged. So the split is decided once, here, at the moment a
 * line is priced, and stored on the order beside the money it describes.
 *
 * How the arithmetic is arranged, and why:
 *
 * - **The rate splits first, in basis points**, `intdiv($rate, 2)` and the
 *   remainder, so the two halves always add to the rate exactly even when it is
 *   odd. Every real GST slab is even in basis points (5% is 500, 18% is 1800),
 *   so the remainder only matters if a tenant types something like 1.25%.
 * - **The centre's half is worked out from its own rate**, and the state's is
 *   whatever is left of the total. That way CGST is what its stated rate
 *   produces, and the two still sum to the tax actually charged — which is the
 *   invariant a bill is checked against, and one the database restates.
 * - **An inclusive price divides by `10000 + rate`**, the full rate, because
 *   that is what the price already carries; only the numerator is the half.
 *
 * Amounts are integers in the currency's minor unit throughout, like all money
 * here (`.ai/rules/migrations.md`). Nothing in this class returns a float.
 */
final readonly class GstSplit
{
    public function __construct(
        public GstTreatment $treatment,
        public int $cgstRate,
        public int $cgst,
        public int $sgstRate,
        public int $sgst,
        public int $igstRate,
        public int $igst,
    ) {}

    /**
     * No tax at all, for a basket with nothing in it or a line priced at nothing.
     */
    public static function none(GstTreatment $treatment): self
    {
        return new self($treatment, 0, 0, 0, 0, 0, 0);
    }

    /**
     * The GST on one amount at one rate.
     *
     * `$pricesIncludeTax` says the amount already carries it, in which case
     * this is the share inside the price rather than something to add on top.
     */
    public static function on(int $amount, int $rate, GstTreatment $treatment, bool $pricesIncludeTax): self
    {
        $total = self::taxOn($amount, $rate, $rate, $pricesIncludeTax);

        if (! $treatment->isSplitInHalves()) {
            return new self($treatment, 0, 0, 0, 0, $rate, $total);
        }

        $centre = intdiv($rate, 2);
        $state = $rate - $centre;

        // The centre's half from its own rate; the state's is the remainder, so
        // the two always add back up to the tax actually charged.
        $cgst = self::taxOn($amount, $centre, $rate, $pricesIncludeTax);

        return new self($treatment, $centre, $cgst, $state, $total - $cgst, 0, 0);
    }

    /**
     * This split and another, added part by part.
     *
     * A bill is priced part by part and rounded once per part, so the totals on
     * an order are these sums rather than a second calculation over the whole.
     * Two splits of different treatments never meet on one bill — the treatment
     * is settled per order — so disagreeing is a bug rather than a case.
     */
    public function plus(self $other): self
    {
        throw_unless($this->treatment === $other->treatment, LogicException::class, 'Two GST treatments cannot be added on one bill.');

        return new self(
            $this->treatment,
            max($this->cgstRate, $other->cgstRate),
            $this->cgst + $other->cgst,
            max($this->sgstRate, $other->sgstRate),
            $this->sgst + $other->sgst,
            max($this->igstRate, $other->igstRate),
            $this->igst + $other->igst,
        );
    }

    /**
     * What the parts come to in all — the one number a total has always carried.
     */
    public function total(): int
    {
        return $this->cgst + $this->sgst + $this->igst;
    }

    /**
     * The whole rate this was levied at, halves added back together.
     */
    public function rate(): int
    {
        return $this->cgstRate + $this->sgstRate + $this->igstRate;
    }

    /**
     * The columns an order or an order line stores this in.
     *
     * @return array{cgst_rate: int, cgst: int, sgst_rate: int, sgst: int, igst_rate: int, igst: int}
     */
    public function columns(): array
    {
        return [
            'cgst_rate' => $this->cgstRate,
            'cgst' => $this->cgst,
            'sgst_rate' => $this->sgstRate,
            'sgst' => $this->sgst,
            'igst_rate' => $this->igstRate,
            'igst' => $this->igst,
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
