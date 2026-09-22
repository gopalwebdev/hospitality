<?php

namespace App\Filament\Tenant\Resources\Orders\Schemas;

use App\Enums\Currency;
use App\Enums\OrderStatus;
use App\Filament\Schemas\PricingFields;
use App\Models\Order;
use App\Models\OrderCharge;
use App\Models\OrderLine;
use App\Models\OrderLineChoice;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;

/**
 * One order as it was placed: where it goes, what was in it, and what it came to.
 *
 * Every name and amount here is the copy the order kept, so an item renamed or
 * repriced since still reads as the guest ordered it. The lines, their choices
 * and the charges arrive loaded with the record (OrderResource).
 */
class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $currency = PricingFields::currency();
        $money = fn (?int $state): string => $currency->format((int) $state);

        return $schema
            ->columns(1)
            ->components([
                Section::make(__('panel.orders.section'))
                    ->icon(Heroicon::OutlinedShoppingBag)
                    ->compact()
                    ->columns(4)
                    ->schema([
                        TextEntry::make('status')
                            ->label(__('panel.orders.status'))
                            ->badge()
                            ->formatStateUsing(fn (OrderStatus $state): string => $state->label())
                            ->color(fn (OrderStatus $state): string => $state->color()),

                        TextEntry::make('created_at')
                            ->label(__('panel.orders.placed_at'))
                            ->dateTime(),

                        TextEntry::make('location_label')
                            ->label(__('panel.orders.location'))
                            ->placeholder('—'),

                        TextEntry::make('menu.name')
                            ->label(__('panel.categories.menu'))
                            ->placeholder('—'),

                        TextEntry::make('cancelled_at')
                            ->label(__('panel.orders.cancelled_at'))
                            ->dateTime()
                            ->visible(fn (Order $record): bool => $record->cancelled_at !== null),

                        TextEntry::make('note')
                            ->label(__('panel.orders.note'))
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make(__('panel.orders.lines'))
                    ->icon(Heroicon::OutlinedListBullet)
                    ->compact()
                    ->schema([
                        RepeatableEntry::make('lines')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make(__('panel.orders.item')),
                                TableColumn::make(__('panel.orders.choices')),
                                TableColumn::make(__('panel.orders.quantity'))->alignment(Alignment::End),
                                TableColumn::make(__('panel.orders.unit_price'))->alignment(Alignment::End),
                                TableColumn::make(__('panel.orders.total'))->alignment(Alignment::End),
                            ])
                            ->schema([
                                TextEntry::make('name'),

                                TextEntry::make('choices_summary')
                                    ->state(fn (OrderLine $record): string => self::choicesOf($record))
                                    ->placeholder('—'),

                                TextEntry::make('quantity'),

                                TextEntry::make('unit_price')
                                    ->formatStateUsing($money),

                                TextEntry::make('total')
                                    ->formatStateUsing($money),
                            ]),
                    ]),

                Section::make(__('panel.orders.totals'))
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->compact()
                    ->columns(4)
                    ->schema([
                        TextEntry::make('subtotal')
                            ->label(__('panel.orders.subtotal'))
                            ->formatStateUsing($money),

                        // CGST and SGST read separately, because that is how
                        // GST is levied and how a tax invoice has to show it.
                        // The amounts are the order's own copies, not a sum
                        // worked out again from the rate.
                        TextEntry::make('tax_parts')
                            ->label(fn (Order $record): string => (string) ($record->prices_include_tax
                                ? __('panel.orders.tax_included')
                                : __('panel.orders.tax')))
                            ->state(fn (Order $record): array => self::taxPartsOf($record, $currency))
                            ->listWithLineBreaks()
                            ->placeholder($currency->format(0)),

                        TextEntry::make('charges_list')
                            ->label(__('panel.orders.charges'))
                            ->state(fn (Order $record): array => $record->charges
                                ->map(fn (OrderCharge $charge): string => $charge->name.' '.$currency->format($charge->amount))
                                ->all())
                            ->listWithLineBreaks()
                            ->placeholder('—'),

                        TextEntry::make('total')
                            ->label(__('panel.orders.total'))
                            ->formatStateUsing($money)
                            ->weight(FontWeight::Bold),
                    ]),
            ]);
    }

    /**
     * An order's GST, part by part, as the invoice for it would show them.
     *
     * Only the parts that carry anything, so a tenant charging no GST reads one
     * blank line rather than two zeroes. The state's half is named as the order
     * was placed — SGST, or UTGST where the tenant said it was in one.
     *
     * @return list<string>
     */
    private static function taxPartsOf(Order $order, Currency $currency): array
    {
        $parts = [
            'CGST' => $order->cgst,
            $order->is_union_territory ? 'UTGST' : 'SGST' => $order->sgst,
        ];

        $shown = [];

        foreach ($parts as $label => $amount) {
            if ($amount > 0) {
                $shown[] = $label.' '.$currency->format($amount);
            }
        }

        return $shown;
    }

    /**
     * What was picked on a line, as one reads it aloud: "Garlic naan · 2 × Extra cheese".
     */
    private static function choicesOf(OrderLine $line): string
    {
        return $line->choices
            ->map(fn (OrderLineChoice $choice): string => $choice->quantity > 1
                ? $choice->quantity.' × '.$choice->name
                : $choice->name)
            ->join(' · ');
    }
}
