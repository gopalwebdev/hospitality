<?php

use App\Filament\Schemas\PricingFields;
use App\Models\TenantSetting;

/*
|--------------------------------------------------------------------------
| Percentages in, basis points out
|--------------------------------------------------------------------------
|
| A unit test rather than a feature one: this is pure arithmetic with no
| database behind it, and it is the boundary that keeps a float rate out of a
| column that has to reconcile to the paisa.
|
*/

it('stores a typed percentage as basis points', function (): void {
    expect(PricingFields::toBasisPoints('5'))->toBe(500)
        ->and(PricingFields::toBasisPoints('18'))->toBe(1800)
        ->and(PricingFields::toBasisPoints('12.5'))->toBe(1250)
        ->and(PricingFields::toBasisPoints('0'))->toBe(0)
        ->and(PricingFields::toBasisPoints('40'))->toBe(4000);
});

it('always stores a rate as an integer, whatever was typed', function (): void {
    expect(PricingFields::toBasisPoints('2.5'))->toBeInt()
        ->and(PricingFields::toBasisPoints(18.0))->toBeInt()
        // Finer than a basis point rounds to one rather than carrying a float
        // onward; no GST rate is quoted to four decimal places.
        ->and(PricingFields::toBasisPoints('5.005'))->toBe(501);
});

it('round-trips a rate through the form without drift', function (): void {
    foreach (['0', '5', '12.5', '18', '28', '40'] as $typed) {
        $stored = PricingFields::toBasisPoints($typed);

        expect(PricingFields::toPercentage($stored))->toBe((float) $typed);
    }
});

it('reads a stored rate the way a menu prints it', function (): void {
    expect(PricingFields::formatRate(0))->toBe('0%')
        ->and(PricingFields::formatRate(500))->toBe('5%')
        ->and(PricingFields::formatRate(1250))->toBe('12.5%')
        ->and(PricingFields::formatRate(1800))->toBe('18%')
        // The 40% demerit rate GST 2.0 introduced in September 2025, which no
        // fixed list of slabs written before then would have held.
        ->and(PricingFields::formatRate(4000))->toBe('40%');
});

it('agrees with the unit every stored rate is counted in', function (): void {
    expect(PricingFields::toBasisPoints('100'))->toBe(TenantSetting::BASIS_POINTS_PER_WHOLE)
        ->and(PricingFields::formatRate(500))->toBe('5%');
});

it('starts a tenant on no rate at all, because the application states none', function (): void {
    // No tax information is hardcoded: a tenant charges nothing until it says
    // what it charges on its Settings page. A starting value of 5% meant every
    // tenant created silently charged a rate nobody had typed.
    expect(TenantSetting::DEFAULT_TAX_RATE_BASIS_POINTS)->toBe(0)
        ->and(PricingFields::formatRate(TenantSetting::DEFAULT_TAX_RATE_BASIS_POINTS))->toBe('0%');
});
