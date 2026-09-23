<?php

namespace App\Filament\Tenant\Resources\PaymentDevices\Tables;

use App\Enums\PaymentDeviceKind;
use App\Filament\Tables\Reordering;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PaymentDevicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('panel.shared.name'))
                    ->searchable(),

                TextColumn::make('kind')
                    ->label(__('panel.payment_devices.kind'))
                    ->badge()
                    ->formatStateUsing(fn (PaymentDeviceKind $state): string => $state->label())
                    ->color('gray'),

                TextColumn::make('identifier')
                    ->label(__('panel.payment_devices.identifier'))
                    ->placeholder('—'),

                IconColumn::make('is_active')
                    ->label(__('panel.payment_devices.is_active'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->label(__('panel.payment_devices.kind'))
                    ->options(PaymentDeviceKind::options()),
            ])
            ->recordActions([
                EditAction::make()
                    ->iconButton()
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->tooltip(__('panel.arrangement.edit')),

                DeleteAction::make()
                    ->iconButton()
                    ->icon(Heroicon::OutlinedTrash)
                    ->tooltip(__('panel.arrangement.delete'))
                    ->modalDescription(__('panel.payment_devices.delete_warning')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            // The order these are offered in when recording a payment,
            // dragged rather than typed — see .ai/rules/tables.md.
            ->reorderable('position')
            ->reorderRecordsTriggerAction(Reordering::trigger())
            ->defaultSort('position')
            ->emptyStateHeading(__('panel.payment_devices.empty_heading'))
            ->emptyStateDescription(__('panel.payment_devices.empty_description'))
            ->emptyStateIcon(Heroicon::OutlinedCreditCard);
    }
}
