<?php

use App\Actions\Baskets\PriceBasket;
use App\Actions\Orders\PlaceOrder;
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
| A tax invoice states the rate and the amount of CGST and SGST (or UTGST) each
| on its own, so the split is decided when a bill is priced and copied onto the
| order. Nothing re-derives it later: halving a stored total does not reliably
| add back up to what was charged.
|
| There is no IGST. Everything sold here is consumed where it is served, so the
| place of supply is always the premises (IGST Act, s. 12(3)) and every bill is
| CGST plus the state's half. Whether that half reads SGST or UTGST is the
| tenant's own statement on its Settings page — one toggle, and no money.
|
*/

/**
 * A tenant charging $rate, with one item on one menu priced at $price.
 *
 * `$itemRate` gives the item a rate of its own, which is what a bill spanning
 * two slabs is made of; null leaves it taxed at the tenant's.
 *
 * @return array{tenant: Tenant, menu: Menu, item: MenuItem}
 */
function taxedTenantWithItem(
    int $rate,
    int $price,
    bool $isUnionTerritory = false,
    bool $pricesIncludeTax = false,
    ?int $itemRate = null,
): array {
    $tenant = Tenant::factory()->create();

    $tenant->settings->update([
        'cgst_rate' => intdiv($rate, 2),
        'sgst_rate' => $rate - intdiv($rate, 2),
        'is_union_territory' => $isUnionTerritory,
        'prices_include_tax' => $pricesIncludeTax,
    ]);

    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $item = MenuItem::factory()
        ->inCategory(MenuCategory::factory()->inMenu($menu)->create())
        ->create(['price' => $price, 'tax_rate' => $itemRate, 'hsn_sac_code' => '996331']);

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
        [['key' => 'k', 'type' => PriceBasket::ITEM, 'id' => $item->getKey(), 'quantity' => 1, 'choices' => []]],
    );
}

it('stores an order\'s GST as halves that add back up to what was charged', function (): void {
    // ₹100.00 at 5% is ₹5.00, which halves cleanly into ₹2.50 each.
    ['tenant' => $tenant, 'menu' => $menu, 'item' => $item] = taxedTenantWithItem(rate: 500, price: 10000);

    $order = orderOneOf($tenant, $menu, $item);

    expect($order->tax)->toBe(500)
        ->and($order->cgst)->toBe(250)
        ->and($order->sgst)->toBe(250)
        // The invariant the database also states, as orders_tax_parts_add_up.
        ->and($order->cgst + $order->sgst)->toBe($order->tax);
});

it('works each half out from its own rate, so an equal rate is an equal amount', function (): void {
    // ₹10.05 at 18% is ₹1.809. Working the whole rate out first and handing the
    // state what was left of it gave the centre ₹0.90 and the state ₹0.91, so a
    // bill stated 9% twice and showed two amounts. 9% of ₹10.05 is ₹0.90 a side.
    ['tenant' => $tenant, 'menu' => $menu, 'item' => $item] = taxedTenantWithItem(rate: 1800, price: 1005);

    $order = orderOneOf($tenant, $menu, $item);
    $line = OrderLine::query()->where('order_id', $order->getKey())->sole();

    expect($order->cgst)->toBe(90)
        ->and($order->sgst)->toBe(90)
        // The tax charged is what the two come to, which orders_tax_parts_add_up restates.
        ->and($order->tax)->toBe(180)
        // One line and no charges, so the line's halves are the order's.
        ->and($line->cgst)->toBe($order->cgst)
        ->and($line->sgst)->toBe($order->sgst)
        ->and($line->taxable_value)->toBe(1005);
});

it('splits a charge evenly on a bill whose prices already carry the tax', function (): void {
    // The bill this was found on: ₹499.00 including 18%, plus a ₹50.00 fee. The
    // item's ₹76.12 halves cleanly and the fee's ₹7.63 does not, so the fee was
    // where a bill came apart — ₹3.81 against ₹3.82, both labelled 9%.
    ['tenant' => $tenant, 'menu' => $menu, 'item' => $item] = taxedTenantWithItem(
        rate: 1800,
        price: 49900,
        isUnionTerritory: true,
        pricesIncludeTax: true,
    );

    Charge::factory()->ofTenant($tenant)->fixedAmount(5000)->onMenus($menu)->create();

    $order = orderOneOf($tenant, $menu, $item);
    $charge = OrderCharge::query()->where('order_id', $order->getKey())->sole();

    // 9% of the ₹42.38 the fee is worth before tax, read either way round.
    expect($charge->cgst)->toBe(381)
        ->and($charge->sgst)->toBe(381)
        ->and($charge->taxable_value)->toBe(4238)
        ->and($order->cgst)->toBe(4187)
        ->and($order->sgst)->toBe(4187)
        ->and($order->tax)->toBe(8374)
        // Included in the prices, so only the fee is added to what is paid.
        ->and($order->total)->toBe(54900);
});

it('splits an item\'s own rate rather than the tenant\'s', function (): void {
    // The tenant's default is 5%, but this item states 18% — a menu holds both,
    // and each line is halved at the rate that line is actually taxed at.
    ['tenant' => $tenant, 'menu' => $menu, 'item' => $item] = taxedTenantWithItem(
        rate: 500,
        price: 10000,
        itemRate: 1800,
    );

    $order = orderOneOf($tenant, $menu, $item);
    $line = OrderLine::query()->where('order_id', $order->getKey())->sole();

    expect($line->tax_rate)->toBe(1800)
        ->and($line->cgst_rate)->toBe(900)
        ->and($line->sgst_rate)->toBe(900)
        ->and($line->cgst)->toBe(900)
        ->and($line->sgst)->toBe(900)
        ->and($order->tax)->toBe(1800);
});

it('calls the state\'s half UTGST without moving any money', function (): void {
    ['tenant' => $tenant, 'menu' => $menu, 'item' => $item] = taxedTenantWithItem(
        rate: 500,
        price: 10000,
        isUnionTerritory: true,
    );

    $order = orderOneOf($tenant, $menu, $item);

    // UTGST rides the SGST columns: only the wording of the invoice differs,
    // and the order keeps its own wording because a tenant can move.
    expect($order->is_union_territory)->toBeTrue()
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

it('taxes a charge at its own code\'s rate, distinct from the tenant\'s, and copies its HSN or SAC', function (): void {
    // The tenant's default is 5%; this charge states 18% of its own and a
    // code, exactly as an item can — a service charge filed under its own SAC
    // rather than the tenant's blended default.
    ['tenant' => $tenant, 'menu' => $menu, 'item' => $item] = taxedTenantWithItem(rate: 500, price: 10000);

    Charge::factory()
        ->ofTenant($tenant)
        ->fixedAmount(5000)
        ->taxedAt(1800)
        ->onMenus($menu)
        ->create(['hsn_sac_code' => '996331']);

    $order = orderOneOf($tenant, $menu, $item);
    $charge = OrderCharge::query()->where('order_id', $order->getKey())->sole();
    $line = OrderLine::query()->where('order_id', $order->getKey())->sole();

    // 18% of ₹50.00, not the tenant's 5% — and the item still carries its
    // own 5%, unaffected by the charge stating a rate of its own.
    expect($charge->tax_rate)->toBe(1800)
        ->and($charge->cgst_rate)->toBe(900)
        ->and($charge->sgst_rate)->toBe(900)
        ->and($charge->cgst)->toBe(450)
        ->and($charge->sgst)->toBe(450)
        ->and($charge->hsn_sac_code)->toBe('996331')
        ->and($line->tax_rate)->toBe(500);
});

it('never lets "use this one rate for every item" reach a charge', function (): void {
    // The item states 18% of its own; the tenant's default is 5%.
    ['tenant' => $tenant, 'menu' => $menu, 'item' => $item] = taxedTenantWithItem(
        rate: 500,
        price: 10000,
        itemRate: 1800,
    );

    // On the project owner's instruction: this toggle only ever reaches
    // MenuItem/MenuCombo::taxRate(). A charge is its own supply, and one with
    // no code of its own keeps following the tenant's rate whatever this says.
    $tenant->settings->update(['tax_overrides_item_rates' => true]);

    Charge::factory()->ofTenant($tenant)->fixedAmount(5000)->onMenus($menu)->create();

    $order = orderOneOf($tenant, $menu, $item);
    $line = OrderLine::query()->where('order_id', $order->getKey())->sole();
    $charge = OrderCharge::query()->where('order_id', $order->getKey())->sole();

    // The toggle reached the item — its own 18% was set aside for the
    // tenant's 5% — and the charge reads the same 5% it always would have:
    // nothing about it changed.
    expect($line->tax_rate)->toBe(500)
        ->and($charge->tax_rate)->toBe(500);
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
