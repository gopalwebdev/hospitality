<?php

namespace App\Filament\Tenant\Resources\Charges\Tables;

use App\Enums\ChargeCalculation;
use App\Filament\Schemas\PricingFields;
use App\Filament\Schemas\TranslatedFields;
use App\Filament\Tables\Reordering;
use App\Filament\Tenant\Resources\Charges\Schemas\ChargeForm;
use App\Models\Charge;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ChargesTable
{
    public static function configure(Table $table): Table
    {
        // Resolved once for the page rather than per row: every charge here
        // belongs to the same tenant.
        $currency = PricingFields::currency();

        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('panel.shared.name'))
                    // A translated column holds a JSON document, so searching
                    // has to name the language it means.
                    ->searchable(query: fn (Builder $query, string $search): Builder => TranslatedFields::search($query, 'name', $search)),

                // One column says both how a charge works and how much: "10%"
                // reads as a share of the bill and "₹20.00" as a fixed sum.
                // Formatted here rather than in the browser, because a panel
                // is server rendered.
                TextColumn::make('calculation')
                    ->label(__('panel.charges.adds'))
                    ->formatStateUsing(fn (Charge $record): string => $record->calculation === ChargeCalculation::Percentage
                        ? PricingFields::formatRate((int) $record->rate)
                        : $currency->format((int) $record->amount))
                    ->badge()
                    ->color('gray'),

                TextColumn::make('menus.name')
                    ->label(__('panel.charges.menus'))
                    ->icon(Heroicon::OutlinedBookOpen)
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),

                IconColumn::make('is_active')
                    ->label(__('panel.charges.is_active'))
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make()
                    ->iconButton()
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->mutateRecordDataUsing(fn (array $data, Charge $record): array => ChargeForm::fillTranslations(
                        ChargeForm::fillValue($data),
                        $record,
                    ))
                    ->mutateDataUsing(fn (array $data): array => ChargeForm::storeValue($data)),

                DeleteAction::make()
                    ->iconButton()
                    ->icon(Heroicon::OutlinedTrash)
                    ->modalDescription(__('panel.charges.delete_warning')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            // The order a guest reads the small print in, dragged rather than
            // typed — see .ai/rules/tables.md.
            ->reorderable('position')
            ->reorderRecordsTriggerAction(Reordering::trigger())
            ->defaultSort('position')
            // Every row lists its menus, so they are loaded once for the page.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('menus:id,name'))
            ->emptyStateHeading(__('panel.charges.empty_heading'))
            ->emptyStateDescription(__('panel.charges.empty_description'))
            ->emptyStateIcon(Heroicon::OutlinedReceiptPercent);
    }
}
