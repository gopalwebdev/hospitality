<?php

namespace App\Filament\Tenant\Resources\Payments\Schemas;

use App\Enums\PaymentMethod;
use App\Filament\Schemas\PricingFields;
use App\Models\Payment;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;

/**
 * One payment as it was recorded: how it was taken, and every order it settled.
 *
 * The allocations arrive loaded with the record (PaymentResource), so reading
 * this page costs no query per row.
 */
class PaymentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $currency = PricingFields::currency();
        $money = fn (?int $state): string => $currency->format((int) $state);

        return $schema
            ->columns(1)
            ->components([
                Section::make(__('panel.payments.section'))
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->compact()
                    ->columns(4)
                    ->schema([
                        TextEntry::make('method')
                            ->label(__('panel.payments.method'))
                            ->badge()
                            ->formatStateUsing(fn (PaymentMethod $state): string => $state->label())
                            ->color(fn (PaymentMethod $state): string => $state->color()),

                        TextEntry::make('paymentDevice.name')
                            ->label(__('panel.payments.device'))
                            ->placeholder('—'),

                        TextEntry::make('reference')
                            ->label(__('panel.payments.reference'))
                            ->placeholder('—'),

                        TextEntry::make('amount')
                            ->label(__('panel.payments.amount'))
                            ->formatStateUsing($money)
                            ->weight(FontWeight::Bold),

                        TextEntry::make('paid_at')
                            ->label(__('panel.payments.paid_at'))
                            ->dateTime(),

                        TextEntry::make('recordedBy.name')
                            ->label(__('panel.payments.recorded_by'))
                            ->placeholder('—'),

                        TextEntry::make('status')
                            ->label(__('panel.payments.status'))
                            ->state(fn (Payment $record): string => $record->isVoided()
                                ? __('panel.payments.voided')
                                : __('panel.payments.live'))
                            ->badge()
                            ->color(fn (Payment $record): string => $record->isVoided() ? 'danger' : 'success'),

                        TextEntry::make('voided_at')
                            ->label(__('panel.payments.voided_at'))
                            ->dateTime()
                            ->visible(fn (Payment $record): bool => $record->isVoided()),

                        TextEntry::make('void_reason')
                            ->label(__('panel.payments.void_reason'))
                            ->placeholder('—')
                            ->visible(fn (Payment $record): bool => $record->isVoided())
                            ->columnSpanFull(),

                        TextEntry::make('note')
                            ->label(__('panel.payments.note'))
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make(__('panel.payments.orders'))
                    ->icon(Heroicon::OutlinedShoppingBag)
                    ->compact()
                    ->schema([
                        RepeatableEntry::make('allocations')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make(__('panel.payments.order')),
                                TableColumn::make(__('panel.payments.amount'))->alignment(Alignment::End),
                            ])
                            ->schema([
                                TextEntry::make('order.id')
                                    ->formatStateUsing(fn (int $state): string => '#'.$state),

                                TextEntry::make('amount')
                                    ->formatStateUsing($money),
                            ]),
                    ]),
            ]);
    }
}
