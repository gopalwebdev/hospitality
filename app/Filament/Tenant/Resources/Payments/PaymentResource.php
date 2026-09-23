<?php

namespace App\Filament\Tenant\Resources\Payments;

use App\Filament\Tenant\Resources\Payments\Pages\ListPayments;
use App\Filament\Tenant\Resources\Payments\Pages\ViewPayment;
use App\Filament\Tenant\Resources\Payments\Schemas\PaymentInfolist;
use App\Filament\Tenant\Resources\Payments\Tables\PaymentsTable;
use App\Models\Payment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * What staff have taken from guests — cash, UPI and card — and what it settled.
 *
 * Nothing is created or edited here: a payment is born from an order's own
 * Record payment action or from a location's Settle action
 * (App\Actions\Payments\RecordPayment), and once recorded it is only ever
 * voided, from this page (App\Actions\Payments\VoidPayment). This doubles as
 * the reconciliation screen: what was taken, by what method, on which
 * machine. Who may look and who may void is PaymentPolicy's business.
 */
class PaymentResource extends Resource
{
    #[\Override]
    protected static ?string $model = Payment::class;

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    /**
     * Just under Orders.
     */
    #[\Override]
    protected static ?int $navigationSort = 3;

    /**
     * Labels are methods rather than static properties because a property is
     * evaluated when the class loads, before the request has chosen a language.
     */
    public static function getModelLabel(): string
    {
        return __('panel.payments.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('panel.payments.plural');
    }

    public static function infolist(Schema $schema): Schema
    {
        return PaymentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentsTable::configure($table);
    }

    /**
     * An opened payment arrives with its device, who recorded it and the
     * orders it settled, so the page asks for none of them per row.
     *
     * @return Builder<Payment>
     */
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        // Filament's own is a query of the resource's model, which is this one.
        /** @var Builder<Payment> $query */
        $query = parent::getRecordRouteBindingEloquentQuery();

        return $query->with([
            'paymentDevice',
            'recordedBy',
            'allocations' => fn ($allocations) => $allocations->orderBy('id'),
            'allocations.order' => fn ($order) => $order->select(['id', 'tenant_id', 'total']),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayments::route('/'),
            'view' => ViewPayment::route('/{record}'),
        ];
    }
}
