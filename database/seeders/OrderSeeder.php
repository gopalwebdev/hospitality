<?php

namespace Database\Seeders;

use App\Actions\Baskets\PriceBasket;
use App\Actions\Inventory\ApplyStockChanges;
use App\Actions\Inventory\RecordStockMovement;
use App\Actions\Inventory\StockChange;
use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\PlaceOrder;
use App\Enums\Role;
use App\Enums\StockMovementReason;
use App\Models\Menu;
use App\Models\MenuAddOnGroup;
use App\Models\MenuAddOnOption;
use App\Models\MenuCombo;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;

/**
 * A realistic spread of orders and stock history against the tenants TenantSeeder makes.
 *
 * TenantSeeder leaves `orders`, `order_lines`, `order_charges` and
 * `stock_movements` empty — a fresh install had nothing in its Filament
 * Orders table and no stock history to show at all. This fills them, and
 * does it the same way the running app does: every order is built by
 * `App\Actions\Orders\PlaceOrder` (never by hand-rolling the money or the
 * GST split, which the `orders_tax_parts_add_up` and
 * `order_lines_tax_rates_add_up` CHECK constraints would refuse the moment
 * the arithmetic drifted) and every count change goes through
 * `App\Actions\Inventory\ApplyStockChanges` or
 * `App\Actions\Inventory\RecordStockMovement`, exactly as a guest's order or
 * a panel restock would write it.
 *
 * **Why PlaceOrder rather than PriceBasket alone.** PriceBasket has no
 * open/served guard and would have been the simpler tool, but an order
 * placed straight from a priced basket would still need every column
 * PlaceOrder itself copies onto `orders`, `order_lines` and
 * `order_charges` — the GST split, the item names in every language, the
 * HSN/SAC code — written out by hand a second time. Going through PlaceOrder
 * means this seeder produces exactly the shape the guest app produces,
 * stock movements included, and never re-derives anything the action
 * already knows how to.
 *
 * **The guard PlaceOrder does carry** is the reason this class exists rather
 * than a few lines added to TenantSeeder. `PlaceOrder` refuses a closed
 * tenant or a menu outside its service window, and `TenantSeeder` gives
 * every tenant Monday off and the breakfast card a 07:00–11:00 window — so
 * placing an order needs the clock pinned to a moment both are open, via
 * `Illuminate\Support\Carbon::setTestNow()` (which `now()` and
 * `CarbonImmutable::now()` both read from the same underlying test clock).
 * `$now` is read once, before anything is pinned, and every later moment is
 * worked out from it rather than from a second call to `now()` — once the
 * clock is pinned, asking it again would answer with the pinned moment
 * instead of the real one. The whole run happens inside one try/finally so
 * the pin is always lifted, whatever a later tenant's orders do.
 *
 * **Idempotency** is coarser here than TenantSeeder's "match by English
 * name": an order has no name to match on, so each tenant's whole block —
 * its opening stock history, its orders, its restock — is skipped outright
 * the moment it already has any order at all. Re-seeding an existing
 * database is safe; it simply leaves a tenant's orders as they stood.
 *
 * @phpstan-import-type BasketLine from PriceBasket
 */
class OrderSeeder extends Seeder
{
    /**
     * @param  PlaceOrder  $placeOrder  resolved by the container, like every parameter of a seeder's run()
     */
    public function run(
        PlaceOrder $placeOrder,
        CancelOrder $cancelOrder,
        ApplyStockChanges $applyStockChanges,
        RecordStockMovement $recordStockMovement,
    ): void {
        $now = CarbonImmutable::now();

        try {
            $this->seedSpiceOrders($now, $placeOrder, $cancelOrder, $applyStockChanges, $recordStockMovement);
            $this->seedSeaviewOrders($now, $placeOrder, $cancelOrder, $applyStockChanges, $recordStockMovement);
            $this->seedSunriseOrders($now, $placeOrder, $applyStockChanges, $recordStockMovement);
        } finally {
            Date::setTestNow();
        }
    }

    /**
     * Spice Garden: intra-state, 5%, prices quoted before tax.
     *
     * Exercises item lines with add-on choices, a combo line, both of the
     * restaurant's charges together (main), only the percentage one
     * (drinks, breakfast), and a breakfast-window order.
     */
    private function seedSpiceOrders(
        CarbonImmutable $now,
        PlaceOrder $placeOrder,
        CancelOrder $cancelOrder,
        ApplyStockChanges $applyStockChanges,
        RecordStockMovement $recordStockMovement,
    ): void {
        $tenant = Tenant::query()->where('slug', 'spice')->sole();

        if (Order::query()->where('tenant_id', $tenant->getKey())->exists()) {
            return;
        }

        [$owner, $staff] = $this->peopleOf($tenant);

        Date::setTestNow($this->openMoment($now->subWeek(), '07:30'));
        $this->seedInitialStockHistory($tenant, $recordStockMovement, $owner);

        $main = $this->menuNamed($tenant, 'Main Menu');
        $drinks = $this->menuNamed($tenant, 'Drinks');
        $breakfast = $this->menuNamed($tenant, 'Breakfast');

        Date::setTestNow($this->openMoment($now, '13:00'));
        $placeOrder($tenant, $main, [
            $this->line('tikka', $this->itemNamed($tenant, 'Paneer Tikka'), 2, [
                [$this->optionNamed($tenant, 'Spice level', 'Medium'), 1],
            ]),
            $this->line('feast', $this->comboNamed($tenant, 'Biryani Feast')),
        ], locationLabel: 'Table 4', note: 'Extra spicy please');

        Date::setTestNow($this->openMoment($now->subDay(), '19:30'));
        $placeOrder($tenant, $main, [
            $this->line('biryani', $this->itemNamed($tenant, 'Hyderabadi Chicken Biryani'), 1, [
                [$this->optionNamed($tenant, 'Portion', 'Full'), 1],
            ]),
            $this->line('naan', $this->itemNamed($tenant, 'Butter Naan'), 3),
        ], locationLabel: 'Table 9');

        // Also holds a counted item, so cancelling this one is the seed's
        // `StockMovementReason::OrderCancelled` example — the other cancelled
        // order (Seaview's) holds nothing counted, and a cancellation with
        // nothing to give back writes no movement at all.
        Date::setTestNow($this->openMoment($now->subWeek(), '12:00'));
        $cancelled = $placeOrder($tenant, $main, [
            $this->line('chicken65', $this->itemNamed($tenant, 'Chicken 65'), 1, [
                [$this->optionNamed($tenant, 'Spice level', 'Hot'), 1],
            ]),
            $this->line('biryani', $this->itemNamed($tenant, 'Hyderabadi Chicken Biryani'), 1, [
                [$this->optionNamed($tenant, 'Portion', 'Half'), 1],
            ]),
        ], locationLabel: 'Table 2', note: 'Customer changed mind');

        Date::setTestNow($this->openMoment($now->subWeek(), '14:00'));
        $cancelOrder($cancelled, $staff);

        Date::setTestNow($this->openMoment($now, '10:00'));
        $placeOrder($tenant, $drinks, [
            $this->line('coffee', $this->itemNamed($tenant, 'Filter Coffee'), 2, [
                [$this->optionNamed($tenant, 'Sugar', 'Less sugar'), 1],
                [$this->optionNamed($tenant, 'Strength', 'Extra strong'), 1],
            ]),
            $this->line('chai', $this->itemNamed($tenant, 'Masala Chai'), 1, [
                [$this->optionNamed($tenant, 'Sugar', 'Regular'), 1],
            ]),
        ], locationLabel: 'Table 6');

        // Within both windows: the breakfast card's own 07:00-11:00 and the
        // tenant's daily 09:00-23:00 — a menu can be served before its
        // tenant opens its doors, and PlaceOrder checks both.
        Date::setTestNow($this->openMoment($now, '09:15'));
        $placeOrder($tenant, $breakfast, [
            $this->line('dosa', $this->itemNamed($tenant, 'Masala Dosa')),
            $this->line('idli', $this->itemNamed($tenant, 'Idli Plate'), 1, [
                [$this->optionNamed($tenant, 'Dosa sides', 'Ghee'), 1],
            ]),
        ], locationLabel: 'Table 1', note: 'Pack it');

        Date::setTestNow($this->openMoment($now, '17:00'));
        $this->restock($tenant, $applyStockChanges, $staff, 'Hyderabadi Chicken Biryani', 10, 'Fresh batch prepared for dinner service');
    }

    /**
     * Seaview Residency: a union territory, 18%, prices already including it.
     *
     * Exercises item lines with add-on choices, a combo line, a charged order
     * and a charge-free one (room requests carries none), and a cancelled
     * order, all under `prices_include_tax`.
     */
    private function seedSeaviewOrders(
        CarbonImmutable $now,
        PlaceOrder $placeOrder,
        CancelOrder $cancelOrder,
        ApplyStockChanges $applyStockChanges,
        RecordStockMovement $recordStockMovement,
    ): void {
        $tenant = Tenant::query()->where('slug', 'seaview')->sole();

        if (Order::query()->where('tenant_id', $tenant->getKey())->exists()) {
            return;
        }

        [$owner, $staff] = $this->peopleOf($tenant);

        Date::setTestNow($this->openMoment($now->subWeek(), '07:30'));
        $this->seedInitialStockHistory($tenant, $recordStockMovement, $owner);

        $inRoomDining = $this->menuNamed($tenant, 'In-room Dining');
        $breakfast = $this->menuNamed($tenant, 'Breakfast');
        $roomRequests = $this->menuNamed($tenant, 'Room Requests');

        Date::setTestNow($this->openMoment($now, '20:00'));
        $placeOrder($tenant, $inRoomDining, [
            $this->line('paneer', $this->itemNamed($tenant, 'Paneer Butter Masala'), 1, [
                [$this->optionNamed($tenant, 'Choose your bread', 'Garlic naan'), 1],
            ]),
            $this->line('thali', $this->comboNamed($tenant, 'Veg Thali')),
        ], locationLabel: 'Room 204', note: 'Deliver by 8pm');

        Date::setTestNow($this->openMoment($now->subDay(), '11:00'));
        $placeOrder($tenant, $roomRequests, [
            $this->line('pillow', $this->itemNamed($tenant, 'Extra Pillow'), 2, [
                [$this->optionNamed($tenant, 'Pillow type', 'Memory foam'), 1],
            ]),
            $this->line('kit', $this->itemNamed($tenant, 'Toiletry Kit')),
            $this->line('water', $this->itemNamed($tenant, 'Water Bottle (1 L)')),
        ], locationLabel: 'Room 310');

        Date::setTestNow($this->openMoment($now->subWeek(), '13:00'));
        $cancelled = $placeOrder($tenant, $inRoomDining, [
            $this->line('butterchicken', $this->itemNamed($tenant, 'Butter Chicken'), 1, [
                [$this->optionNamed($tenant, 'Choose your bread', 'Tandoori roti'), 1],
            ]),
            $this->line('naan', $this->itemNamed($tenant, 'Butter Naan'), 2),
        ], locationLabel: 'Room 118', note: 'Wrong room, cancel');

        Date::setTestNow($this->openMoment($now->subWeek(), '15:00'));
        $cancelOrder($cancelled, $staff);

        Date::setTestNow($this->openMoment($now, '09:00'));
        $placeOrder($tenant, $breakfast, [
            $this->line('bhurji', $this->itemNamed($tenant, 'Egg Bhurji'), 1, [
                [$this->optionNamed($tenant, 'Spice level', 'Mild'), 1],
            ]),
            $this->line('omelette', $this->itemNamed($tenant, 'Omelette')),
        ], locationLabel: 'Room 220');

        Date::setTestNow($this->openMoment($now, '17:00'));
        $this->restock($tenant, $applyStockChanges, $staff, 'Extra Pillow', 10, 'Linen delivery arrived');
    }

    /**
     * Sunrise Multispecialty Hospital: inter-state, and every line taxed at
     * the tenant's own rate regardless of an item's — `tax_overrides_item_rates`.
     */
    private function seedSunriseOrders(
        CarbonImmutable $now,
        PlaceOrder $placeOrder,
        ApplyStockChanges $applyStockChanges,
        RecordStockMovement $recordStockMovement,
    ): void {
        $tenant = Tenant::query()->where('slug', 'sunrise')->sole();

        if (Order::query()->where('tenant_id', $tenant->getKey())->exists()) {
            return;
        }

        [$owner, $staff] = $this->peopleOf($tenant);

        Date::setTestNow($this->openMoment($now->subWeek(), '07:30'));
        $this->seedInitialStockHistory($tenant, $recordStockMovement, $owner);

        $patientCare = $this->menuNamed($tenant, 'Patient Care');

        Date::setTestNow($this->openMoment($now, '13:00'));
        $placeOrder($tenant, $patientCare, [
            $this->line('thali', $this->itemNamed($tenant, 'Regular Thali')),
            $this->line('coffee', $this->itemNamed($tenant, 'Filter Coffee'), 1, [
                [$this->optionNamed($tenant, 'Sugar', 'Regular'), 1],
            ]),
        ], locationLabel: 'Ward 3B');

        Date::setTestNow($this->openMoment($now->subDay(), '12:30'));
        $placeOrder($tenant, $patientCare, [
            $this->line('tray', $this->itemNamed($tenant, 'Attender Meal Tray')),
            $this->line('pillow', $this->itemNamed($tenant, 'Extra Pillow'), 2, [
                [$this->optionNamed($tenant, 'Pillow type', 'Feather'), 1],
            ]),
        ], locationLabel: 'Ward 5A');

        Date::setTestNow($this->openMoment($now->subWeek(), '09:00'));
        $placeOrder($tenant, $patientCare, [
            $this->line('diabetic', $this->itemNamed($tenant, 'Diabetic Thali')),
            $this->line('wheelchair', $this->itemNamed($tenant, 'Wheelchair Assistance')),
        ], locationLabel: 'Ward 1C', note: 'Diabetic patient, low sugar');

        Date::setTestNow($this->openMoment($now, '17:00'));
        $this->restock($tenant, $applyStockChanges, $staff, 'Extra Pillow', 5, 'Housekeeping restocked the ward store');
    }

    /**
     * Give every counted item and option this tenant already has a starting
     * `count` movement, exactly as `MenuItemObserver::created()` would have
     * written one had TenantSeeder not run with model events switched off.
     *
     * Skips a row with nothing to count and one already zero — a movement
     * changing a count by zero is refused by `stock_movements_change_not_zero`,
     * and a freshly created item that opens at zero (Mutton Dum Biryani) never
     * got an opening count in the first place.
     */
    private function seedInitialStockHistory(Tenant $tenant, RecordStockMovement $recordStockMovement, User $owner): void
    {
        MenuItem::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('stock_quantity', '>', 0)
            ->get(['id', 'tenant_id', 'stock_quantity'])
            // The `> 0` filter is a database condition, not something static
            // analysis can read back onto the column's own `int|null` type,
            // hence the cast — the same one ApplyStockChanges itself makes
            // of a value it has already established is not null.
            ->each(fn (MenuItem $item): StockMovement => $recordStockMovement($item, (int) $item->stock_quantity, StockMovementReason::Count, user: $owner));

        MenuAddOnOption::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('stock_quantity', '>', 0)
            ->get(['id', 'tenant_id', 'stock_quantity'])
            ->each(fn (MenuAddOnOption $option): StockMovement => $recordStockMovement($option, (int) $option->stock_quantity, StockMovementReason::Count, user: $owner));
    }

    /**
     * A restock after the day's orders — the fourth `StockMovementReason`
     * none of placing or cancelling an order writes — attributed to a member
     * of staff rather than the owner who opened the count.
     */
    private function restock(Tenant $tenant, ApplyStockChanges $applyStockChanges, User $staff, string $itemName, int $quantity, string $note): void
    {
        $item = $this->itemNamed($tenant, $itemName);

        $applyStockChanges(
            [StockChange::add(MenuItem::class, $item->getKey(), $quantity)],
            StockMovementReason::Restock,
            user: $staff,
            note: $note,
        );
    }

    /**
     * This tenant's owner and its staff member, both seeded by TenantSeeder.
     *
     * @return array{0: User, 1: User}
     */
    private function peopleOf(Tenant $tenant): array
    {
        return [
            $tenant->users()->role(Role::Owner->value)->firstOrFail(),
            $tenant->users()->role(Role::Staff->value)->firstOrFail(),
        ];
    }

    /**
     * A moment at the given wall-clock time, moved back a day if it would
     * land on the seeded Monday holiday (`TenantSeeder::seedOpeningHours()`).
     *
     * Every seeded tenant closes on Mondays, so placing an order at a moment
     * that happened to fall on one would be refused with
     * `OrderRefusal::StoreClosed` before a single line was even priced.
     */
    private function openMoment(CarbonImmutable $base, string $time): CarbonImmutable
    {
        $moment = $base->setTimeFromTimeString($time);

        return $moment->isMonday() ? $moment->subDay() : $moment;
    }

    private function menuNamed(Tenant $tenant, string $englishName): Menu
    {
        return Menu::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('name->en', $englishName)
            ->sole();
    }

    private function itemNamed(Tenant $tenant, string $englishName): MenuItem
    {
        return MenuItem::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('name->en', $englishName)
            ->sole();
    }

    private function comboNamed(Tenant $tenant, string $englishName): MenuCombo
    {
        return MenuCombo::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('name->en', $englishName)
            ->sole();
    }

    /**
     * An option by its own name, within the add-on group named — the group
     * name alone is not unique enough to skip, since two tenants share the
     * same library of group names.
     */
    private function optionNamed(Tenant $tenant, string $groupName, string $optionName): MenuAddOnOption
    {
        $group = MenuAddOnGroup::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('name->en', $groupName)
            ->sole();

        return MenuAddOnOption::query()
            ->where('menu_add_on_group_id', $group->getKey())
            ->where('name->en', $optionName)
            ->sole();
    }

    /**
     * One line of a basket, exactly the shape the guest app sends
     * `PlaceOrder` — see `orderLine()` in tests/Feature/Tenant/PlaceOrderTest.php.
     *
     * @param  list<array{0: MenuAddOnOption, 1: int}>  $choices  each option and how many of it
     * @return BasketLine
     */
    private function line(string $key, MenuItem|MenuCombo $thing, int $quantity = 1, array $choices = []): array
    {
        return [
            'key' => $key,
            'type' => $thing instanceof MenuCombo ? PriceBasket::COMBO : PriceBasket::ITEM,
            'id' => $thing->getKey(),
            'quantity' => $quantity,
            'choices' => array_map(
                static fn (array $choice): array => ['optionId' => $choice[0]->getKey(), 'quantity' => $choice[1]],
                $choices,
            ),
        ];
    }
}
