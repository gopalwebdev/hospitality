<?php

namespace App\Filament\Tenant\Resources\PaymentDevices\Schemas;

use App\Enums\PaymentDeviceKind;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * A card machine or a QR code payments are recorded against.
 *
 * name is a plain string here, not translated — unlike Location, no guest
 * ever reads "Counter machine 1" (.ai/rules/models.md), so there is no
 * locale switcher on this form.
 */
class PaymentDeviceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('panel.payment_devices.section'))
                    ->icon(Heroicon::OutlinedCreditCard)
                    ->compact()
                    ->schema([
                        Select::make('kind')
                            ->label(__('panel.payment_devices.kind'))
                            ->options(PaymentDeviceKind::options())
                            ->required()
                            ->native(false),

                        TextInput::make('name')
                            ->label(__('panel.shared.name'))
                            ->required()
                            ->maxLength(64),

                        TextInput::make('identifier')
                            ->label(__('panel.payment_devices.identifier'))
                            ->maxLength(64),

                        Toggle::make('is_active')
                            ->label(__('panel.payment_devices.is_active'))
                            ->default(true)
                            ->inline(false),
                    ]),
            ]);
    }
}
