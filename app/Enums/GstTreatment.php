<?php

namespace App\Enums;

/**
 * How one bill's GST is levied: as two halves, or as one inter-state tax.
 *
 * The rate a tenant charges is one number — 5%, 18% — and this is what that
 * number is split into on the invoice. It is a property of the *supply*, not of
 * the item, which is why it is settled once per order and stored on it:
 * a rate changes, a tenant moves, and an order is a copy of what was charged.
 *
 * **A tenant says which of these it charges under**, on the Settings page.
 * Nothing here works it out from an address or from a GSTIN: a table of which
 * union territories have a legislature is tax policy baked into the code, which
 * is exactly what a tenant must be able to state for itself.
 *
 * Hospitality is almost always `IntraState`, which is why it is the starting
 * choice. The place of supply for lodging, and for anything eaten or used on
 * the premises, is where the premises are (IGST Act, s. 12(3)), so a guest from
 * another state is still an intra-state supply.
 *
 * CGST is always half. What the other half is called — SGST or UTGST — is the
 * only thing that varies between the two split cases; the money is identical.
 */
enum GstTreatment: string
{
    /** Inside one state: CGST to the centre, SGST to the state. */
    case IntraState = 'intra-state';

    /** Inside a union territory with no legislature: CGST, then UTGST in place of SGST. */
    case UnionTerritory = 'union-territory';

    /** Across a state line: one IGST at the whole rate, and no halves. */
    case InterState = 'inter-state';

    /**
     * Whether the rate splits into a centre's half and a state's.
     *
     * The single place that distinction is made: everything that prices a bill
     * asks this rather than comparing cases, so a fourth treatment cannot leave
     * an arithmetic branch behind.
     */
    public function isSplitInHalves(): bool
    {
        return $this !== self::InterState;
    }

    /**
     * What the state's half is called on an invoice, or null when there is not one.
     */
    public function stateTaxLabel(): ?string
    {
        return match ($this) {
            self::IntraState => 'SGST',
            self::UnionTerritory => 'UTGST',
            self::InterState => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::IntraState => 'CGST + SGST',
            self::UnionTerritory => 'CGST + UTGST',
            self::InterState => 'IGST',
        };
    }

    /**
     * Every treatment, keyed by stored value, for a select field.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            static function (array $options, self $treatment): array {
                $options[$treatment->value] = $treatment->label();

                return $options;
            },
            [],
        );
    }
}
