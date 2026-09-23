<?php

namespace App\Filament\Schemas;

use App\Enums\Currency;
use App\Enums\PaymentDeviceKind;
use App\Enums\PaymentMethod;
use App\Models\PaymentDevice;
use App\Models\Tenant;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

/**
 * The fields a modal fills in to record a payment: how, on what, for how much.
 *
 * Shared between the order's own Record payment action and the Settle action
 * on a location — both hand RecordPayment the same shape of data, so the
 * fields asking for it live here once rather than twice.
 *
 * PaymentMethod::deviceKind() and ::takesReference() are the single source of
 * truth for which device a method may name and whether a reference applies;
 * the device and reference fields read those rather than deciding for
 * themselves.
 */
final class PaymentFields
{
    /**
     * How the money was taken. Live, because the fields below read it.
     */
    public static function method(): Select
    {
        return Select::make('method')
            ->label(__('panel.orders.payment_method'))
            ->options(PaymentMethod::options())
            ->required()
            ->native(false)
            ->live();
    }

    /**
     * The machine or QR code this was taken on — offered, and required, only
     * for a method that names a device kind at all.
     */
    public static function device(Tenant $tenant): Select
    {
        return Select::make('payment_device_id')
            ->label(__('panel.orders.payment_device'))
            ->options(fn (Get $get): array => self::deviceOptions($get, $tenant))
            ->visible(fn (Get $get): bool => self::methodFrom($get)?->deviceKind() instanceof PaymentDeviceKind)
            ->required(fn (Get $get): bool => self::methodFrom($get)?->deviceKind() instanceof PaymentDeviceKind)
            ->native(false);
    }

    /**
     * The transaction, UTR or approval number — only for a method that carries one.
     */
    public static function reference(): TextInput
    {
        return TextInput::make('reference')
            ->label(__('panel.orders.payment_reference'))
            ->maxLength(64)
            ->visible(fn (Get $get): bool => self::methodFrom($get)?->takesReference() ?? false);
    }

    /**
     * What was taken, typed in major units and converted through the
     * tenant's own Currency — the same conversion PricingFields resolves its
     * currency from, so nothing here hand-rolls a /100.
     */
    public static function amount(Currency $currency, float|Closure $default): TextInput
    {
        return TextInput::make('amount')
            ->label(__('panel.orders.payment_amount'))
            ->required()
            ->numeric()
            ->minValue(0.01)
            ->step(0.01)
            ->prefix($currency->symbol())
            ->default($default);
    }

    public static function note(): Textarea
    {
        return Textarea::make('note')
            ->label(__('panel.orders.payment_note'))
            ->maxLength(200);
    }

    /**
     * The method currently picked in the form, or null while nothing is.
     */
    public static function methodFrom(Get $get): ?PaymentMethod
    {
        $value = $get('method');

        return is_string($value) ? PaymentMethod::tryFrom($value) : null;
    }

    /**
     * This tenant's active devices of the kind the picked method takes.
     *
     * once(): Filament asks a select for its options more than once while it
     * builds and validates one form.
     *
     * @return array<int, string>
     */
    private static function deviceOptions(Get $get, Tenant $tenant): array
    {
        $method = self::methodFrom($get);

        if (! $method instanceof PaymentMethod || ! $method->deviceKind() instanceof PaymentDeviceKind) {
            return [];
        }

        return once(fn (): array => PaymentDevice::query()
            ->where('tenant_id', $tenant->getKey())
            ->active()
            ->forMethod($method)
            ->orderBy('position')
            ->pluck('name', 'id')
            ->all());
    }
}
