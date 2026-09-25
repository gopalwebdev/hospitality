<?php

namespace App\Filament\Tenant\Resources\Orders;

use App\Filament\Tenant\Resources\Orders\Pages\ListOrders;
use App\Filament\Tenant\Resources\Orders\Schemas\OrderInfolist;
use App\Filament\Tenant\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use BackedEnum;
use Closure;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Every order placed, newest first, to read and — when one has to be called off — cancel.
 *
 * Nothing is edited or deleted here: an order is a record of what was ordered,
 * not a draft, so one that should not stand is cancelled and CancelOrder puts
 * back what it took from stock. Nothing is created here either, but orders are
 * no longer only a guest's phone: New order links to the panel's own counter
 * (App\Filament\Tenant\Pages\TakeOrder), which calls the same PlaceOrder the
 * guest API does. The steps staff move an order through come with the screens
 * that move it. Who may look, who may take one and who may cancel is
 * OrderPolicy's business.
 */
class OrderResource extends Resource
{
    #[\Override]
    protected static ?string $model = Order::class;

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    /**
     * At the top: what the floor looks at most.
     */
    #[\Override]
    protected static ?int $navigationSort = 2;

    /**
     * Labels are methods rather than static properties because a property is
     * evaluated when the class loads, before the request has chosen a language.
     */
    public static function getModelLabel(): string
    {
        return __('panel.orders.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('panel.orders.plural');
    }

    public static function infolist(Schema $schema): Schema
    {
        return OrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table);
    }

    /**
     * Load everything one order's infolist reads, onto the instance it was handed.
     *
     * An order is read in a **modal**, on the project owner's instruction, so
     * there is no view page and no route binding to hang the eager loads on:
     * whatever opens the modal calls this first. Without it the infolist would
     * lazy-load its lines, choices, charges and payments while rendering, which
     * Model::shouldBeStrict() refuses outright (`.ai/rules/app.md`).
     *
     * The payment sum is aliased exactly `amount_paid` and constrained to live
     * payments — the contract Order::amountPaid() reads it back under, and the
     * same one OrdersTable::configure() follows for the list. The Record
     * payment action's visibility, its amount default and the infolist's own
     * outstanding line all read that one value rather than asking again.
     */
    public static function loadForView(Order $record): void
    {
        // loadMissing, not load: the list already eager-loads each row's menu
        // with the same two columns, and loading it again is the duplicate the
        // query guard throws on (`.ai/rules/app.md`). Everything else the
        // infolist reads is genuinely absent from a list row and does load.
        if ($record->amountPaidWasLoaded()) {
            $record->loadMissing(self::viewRelations());

            return;
        }

        $fresh = Order::query()
            ->whereKey($record->getKey())
            ->withSum(['paymentAllocations as amount_paid' => fn ($allocations) => $allocations
                ->whereHas('payment', fn ($payment) => $payment->live())], 'amount')
            ->firstOrFail();

        $record->setRawAttributes($fresh->getAttributes());
        $record->loadMissing(self::viewRelations());
    }

    /**
     * The lines in order, their choices, the charges, and the payments that
     * settled it with their devices and who recorded them — so the infolist
     * asks for none of them per row.
     *
     * @return array<int|string, Closure|string>
     */
    public static function viewRelations(): array
    {
        return [
            'menu' => fn ($menu) => $menu->select(['id', 'name']),
            'lines' => fn ($lines) => $lines->orderBy('position')->orderBy('id'),
            'lines.choices',
            'charges' => fn ($charges) => $charges->orderBy('position')->orderBy('id'),
            'paymentAllocations' => fn ($allocations) => $allocations->orderByDesc('id'),
            'paymentAllocations.payment.paymentDevice',
            'paymentAllocations.payment.recordedBy',
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
        ];
    }
}
