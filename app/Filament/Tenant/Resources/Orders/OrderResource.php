<?php

namespace App\Filament\Tenant\Resources\Orders;

use App\Filament\Tenant\Resources\Orders\Pages\ListOrders;
use App\Filament\Tenant\Resources\Orders\Pages\ViewOrder;
use App\Filament\Tenant\Resources\Orders\Schemas\OrderInfolist;
use App\Filament\Tenant\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The orders guests have placed, newest first, to read and — when one has to be called off — cancel.
 *
 * Nothing is created or edited here: guests place orders from a menu
 * (App\Actions\Orders\PlaceOrder), and an order is a record of what was
 * ordered, not a draft. Cancelling puts back what it took from stock
 * (CancelOrder). The steps staff move an order through come with the screens
 * that move it. Who may look and who may cancel is OrderPolicy's business.
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
     * An opened order arrives with its menu, its lines in order, their choices and its charges, so the page asks for none of them per line.
     *
     * @return Builder<Order>
     */
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->with([
            'menu' => fn ($menu) => $menu->select(['id', 'name']),
            'lines' => fn ($lines) => $lines->orderBy('position')->orderBy('id'),
            'lines.choices',
            'charges' => fn ($charges) => $charges->orderBy('position')->orderBy('id'),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }
}
