<?php

namespace App\Filament\Tenant\Resources\PaymentDevices;

use App\Filament\Tenant\Resources\PaymentDevices\Pages\ListPaymentDevices;
use App\Filament\Tenant\Resources\PaymentDevices\Schemas\PaymentDeviceForm;
use App\Filament\Tenant\Resources\PaymentDevices\Tables\PaymentDevicesTable;
use App\Models\PaymentDevice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The card machines and QR codes a tenant records payments against.
 *
 * Created and edited in modals on the list — the Charges shape. name is a
 * plain string, not translated: no guest ever reads "Counter machine 1"
 * (.ai/rules/models.md). Behind settings.manage: a card machine is tenant
 * configuration, the same audience as Charges.
 */
class PaymentDeviceResource extends Resource
{
    #[\Override]
    protected static ?string $model = PaymentDevice::class;

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    #[\Override]
    protected static ?int $navigationSort = 87;

    #[\Override]
    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Labels are methods rather than static properties because a property is
     * evaluated when the class loads, before the request has chosen a language.
     */
    public static function getModelLabel(): string
    {
        return __('panel.payment_devices.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('panel.payment_devices.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return PaymentDeviceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentDevicesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentDevices::route('/'),
        ];
    }
}
