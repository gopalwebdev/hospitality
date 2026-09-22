<?php

namespace App\Filament\Tenant\Resources\TaxCodes\Tables;

use App\Filament\Schemas\PricingFields;
use App\Filament\Tenant\Resources\TaxCodes\Schemas\TaxCodeForm;
use App\Models\TaxCode;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The catalogue and this tenant's own, in one list.
 *
 * A **Source** badge is what tells them apart, because the row actions are
 * already missing from a catalogue row (TaxCodePolicy refuses them) and a
 * missing button explains nothing on its own.
 */
class TaxCodesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label(__('panel.tax_codes.code'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('description')
                    ->label(__('panel.tax_codes.description'))
                    ->searchable()
                    ->wrap(),

                // Formatted here rather than in the browser: a panel is server
                // rendered and has no client to format in.
                TextColumn::make('tax_rate')
                    ->label(__('panel.tax_codes.tax_rate'))
                    ->formatStateUsing(fn (TaxCode $record): string => PricingFields::formatRate($record->tax_rate))
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('tenant_id')
                    ->label(__('panel.tax_codes.source'))
                    ->formatStateUsing(fn (TaxCode $record): string => $record->isFromCatalogue()
                        ? __('panel.tax_codes.source_catalogue')
                        : __('panel.tax_codes.source_own'))
                    ->badge()
                    ->color(fn (TaxCode $record): string => $record->isFromCatalogue() ? 'gray' : 'primary'),
            ])
            ->defaultSort('code')
            ->recordActions([
                EditAction::make()
                    ->iconButton()
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->tooltip(__('panel.shared.edit'))
                    ->mutateRecordDataUsing(fn (array $data): array => TaxCodeForm::fillValue($data))
                    ->mutateDataUsing(fn (array $data): array => TaxCodeForm::storeValue($data)),

                DeleteAction::make()
                    ->iconButton()
                    ->tooltip(__('panel.shared.delete')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading(__('panel.tax_codes.empty_heading'))
            ->emptyStateDescription(__('panel.tax_codes.empty_description'));
    }
}
