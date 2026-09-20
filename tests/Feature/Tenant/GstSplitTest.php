<?php

use App\Actions\Menus\QuoteBasket;
use App\Actions\Orders\PlaceOrder;
use App\Enums\GstTreatment;
use App\Models\Charge;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderCharge;
use App\Models\OrderLine;
use App\Models\Tenant;

/*
|--------------------------------------------------------------------------
| Splitting GST the way an invoice has to show it
|--------------------------------------------------------------------------
|
| A tax invoice states the rate and the amount of CGST and SGST (or UTGST, or
| IGST) each on its own, so the split is decided when a bill is priced and
| copied onto the order. Nothing re-derives it later: halving a stored total
| does not reliably add back up to what was charged.
|
| Which of the three a tenant charges under is a tenant's own statement on its
| Settings page. Nothing here works it out from an address or a GSTIN.
|
*/

/**
 * A tenant charging $rate, with one item on one menu priced at $price.
 *
 * @return array{tenant: Tenant, menu: Menu, item: MenuItem}
 */
function taxedTenantWithItem(int $rate, int $price, GstTreatment $treatment = GstTreatment::IntraState, bool $pricesIncludeTax = false): array
{
    $tenant = Tenant::factory()->create();

    $tenant->settings->update([
        'cgst_rate' => intdiv($rate, 2),
        'sgst_rate' => $rate - intdiv($rate, 2),
        'gst_treatment' => $treatment,
        'prices_include_tax' => $pricesIncludeTax,
    ]);

    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $item = MenuItem::factory()
        ->inCategory(MenuCategory::factory()->inMenu($menu)->create())
        ->create(['price' => $price, 'tax_rate' => null, 'hsn_sac_code' => '996331']);

    return ['tenant' => $tenant->fresh() ?? $tenant, 'menu' => $menu, 'item' => $item];
}

/**
 * That item ordered once, the way a guest orders it.
 */
function orderOneOf(Tenant $tenant, Menu $menu, MenuItem $item): Order
{
    return app(PlaceOrder::class)(
        $tenant,
        $menu,
        [['key' => 'k', 'type' => QuoteBasket::ITEM, 'id' => $item->getKey(), 'quantity' => 1, 'choices' => []]],
    );
}

it('stores an order\'s GST as halves that add back up to what was charged', function (): void {
    // ₹100.00 at 5% is ₹5.00, which halves cleanly into ₹2.50 each.
    ['tenant' => $tenant, 'menu' => $menu, 'item' => $item] = taxedTenantWithItem(rate: 500, price: 10000);

    $order = orderOneOf($tenant, $menu, $item);

    expect($order->gst_treatment)->toBe(GstTreatment::IntraState)
        ->and($order->tax)->toBe(500)
        ->and($order->cgst)->toBe(250)
        ->and($order->sgst)->toBe(250)
        ->and($order->igst)->toBe(0)
        // The invariant the database also states, as orders_tax_parts_add_up.
        ->and($order->cgst + $order->sgst + $order->igst)
        ->toBe($order->tax);
});

it('gives the odd paisa to the state rather than losing it', function (): void {
    // ₹99.90 at 5% is ₹4.995 — 500 paisa rounded, which will not halve evenly.
    ['tenant' => $tenant, 'menu' => $menu, 'item' => $item] = taxedTenantWithItem(rate: 500, price: 9990);

    $order = orderOneOf($tenant, $menu, $item);
    $line = OrderLine::query()->where('order_id', $order->getKey())->sole();

    // The centre's half comes from its own 2.5% — 9990 × 250 / 10000 is 249.75,
    // rounded to 250 — and the state's is whatever is left of the 500 charged.
    expect($order->tax)->toBe(500)
        ->and($order->cgst)->toBe(250)
        ->and($order->sgst)->toBe(250)
        // One line and no charges, so the line's halves are the order's.
        ->and($line->cgst)->toBe($order->cgst)
        ->and($line->sgst)->toBe($order->sgst)
        ->and($line->taxable_value)->toBe(9990);
});

it('levies one IGST and no halves when the tenant says it supplies inter-state', function (): void {
    ['tenant' => $tenant, 'menu' => $menu, 'item' => $item] = taxedTenantWithItem(
        rate: 1800,
        price: 10000,
        treatment: GstTreatment::InterState,
    );

    $order = orderOneOf($tenant, $menu, $item);
    $line = OrderLine::query()->where('order_id', $order->getKey())->sole();

    expect($order->gst_treatment)->toBe(GstTreatment::InterState)
        ->and($order->tax)->toBe(1800)
        ->and($order->igst)->toBe(1800)
        ->and($order->cgst)->toBe(0)
        ->and($order->sgst)->toBe(0)
        ->and($line->igst_rate)->toBe(1800)
        ->and($line->cgst_rate)->toBe(0);
});

it('calls the state\'s half UTGST without moving any money', function (): void {
    ['tenant' => $tenant, 'menu' => $menu, 'item' => $item] = taxedTenantWithItem(
        rate: 500,
        price: 10000,
        treatment: GstTreatment::UnionTerritory,
    );

    $order = orderOneOf($tenant, $menu, $item);

    // UTGST rides the SGST columns: only the wording of the invoice differs.
    expect($order->gst_treatment)->toBe(GstTreatment::UnionTerritory)
        ->and($order->gst_treatment->stateTaxLabel())->toBe('UTGST')
        ->and($order->cgst)->toBe(250)
        ->and($order->sgst)->toBe(250);
});

it('copies a line\'s own split and its HSN or SAC code onto the order', function (): void {
    ['tenant' => $tenant, 'menu' => $menu, 'item' => $item] = taxedTenantWithItem(rate: 1800, price: 20000);

    $order = orderOneOf($tenant, $menu, $item);
    $line = OrderLine::query()->where('order_id', $order->getKey())->sole();

    expect($line->tax_rate)->toBe(1800)
        ->and($line->cgst_rate)->toBe(900)
        ->and($line->sgst_rate)->toBe(900)
        ->and($line->cgst)->toBe(1800)
        ->and($line->sgst)->toBe(1800)
        ->and($line->taxable_value)->toBe(20000)
        // An order outlives the item, so the code it was invoiced under is a copy.
        ->and($line->hsn_sac_code)->toBe('996331');

    $item->update(['hsn_sac_code' => '999999']);

    expect($line->fresh()?->hsn_sac_code)->toBe('996331');
});

it('taxes a service charge with the supply rather than after it', function (): void {
    ['tenant' => $tenant, 'menu' => $menu, 'item' => $item] = taxedTenantWithItem(rate: 500, price: 10000);

    // 10% of the ₹100.00 subtotal is ₹10.00, itself taxed at the tenant's 5%.
    Charge::factory()->ofTenant($tenant)->percentage(1000)->onMenus($menu)->create();

    $order = orderOneOf($tenant, $menu, $item);
    $charge = OrderCharge::query()->where('order_id', $order->getKey())->sole();

    expect($charge->amount)->toBe(1000)
        ->and($charge->tax_rate)->toBe(500)
        ->and($charge->taxable_value)->toBe(1000)
        ->and($charge->cgst)->toBe(25)
        ->and($charge->sgst)->toBe(25)
        // The bill's tax carries the item's ₹5.00 and the charge's ₹0.50.
        ->and($order->tax)->toBe(550)
        ->and($order->total)->toBe(10000 + 550 + 1000);
});

it('takes the tax out of a price that already carries it', function (): void {
    // ₹105.00 including 5% holds ₹5.00 of GST and ₹100.00 of value.
    ['tenant' => $tenant, 'menu' => $menu, 'item' => $item] = taxedTenantWithItem(
        rate: 500,
        price: 10500,
        pricesIncludeTax: true,
    );

    $order = orderOneOf($tenant, $menu, $item);
    $line = OrderLine::query()->where('order_id', $order->getKey())->sole();

    expect($order->tax)->toBe(500)
        ->and($order->cgst)->toBe(250)
        ->and($order->sgst)->toBe(250)
        ->and($line->taxable_value)->toBe(10000)
        // Included, so it is not added again.
        ->and($order->total)->toBe(10500);
});
