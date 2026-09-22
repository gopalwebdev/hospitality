<?php

namespace App\Filament\Tenant\Resources\TaxCodes\Schemas;

use App\Filament\Schemas\PricingFields;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * One HSN or SAC code a tenant keeps for itself.
 *
 * The rate is typed as the percentage an accountant quotes and stored in basis
 * points, converted in `storeValue()` / `fillValue()` and nowhere else — the
 * same arrangement as `ChargeForm` and for the same reason.
 *
 * No helper text: the labels say what the fields are (`.ai/rules/filament.md`).
 */
class TaxCodeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label(__('panel.tax_codes.code'))
                    ->required()
                    ->maxLength(8),

                TextInput::make('tax_rate_percentage')
                    ->label(__('panel.tax_codes.tax_rate'))
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->step(0.01)
                    ->suffix('%'),

                TextInput::make('description')
                    ->label(__('panel.tax_codes.description'))
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    /**
     * The typed percentage as the basis points a row stores.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function storeValue(array $data): array
    {
        $data['tax_rate'] = PricingFields::toBasisPoints($data['tax_rate_percentage'] ?? 0);

        unset($data['tax_rate_percentage']);

        return $data;
    }

    /**
     * The reverse, for a form being filled from a row.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillValue(array $data): array
    {
        $data['tax_rate_percentage'] = PricingFields::toPercentage((int) ($data['tax_rate'] ?? 0));

        return $data;
    }
}
