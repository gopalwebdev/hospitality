<?php

namespace App\Filament\Tenant\Resources\PaymentDevices\Pages;

use App\Filament\Tenant\Resources\PaymentDevices\PaymentDeviceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

/**
 * Every card machine and QR code this tenant records payments against.
 */
class ListPaymentDevices extends ListRecords
{
    #[\Override]
    protected static string $resource = PaymentDeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('panel.payment_devices.create'))
                ->icon(Heroicon::OutlinedPlus),
        ];
    }
}
