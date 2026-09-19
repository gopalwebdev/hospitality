<?php

namespace App\Filament\Tables;

use App\Actions\Inventory\ApplyStockChanges;
use App\Actions\Inventory\StockChange;
use App\Enums\StockMovementReason;
use App\Models\MenuItem;
use App\Models\StockMovement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Restocking an item, and reading back how its count got where it is, from any table of items.
 *
 * Adjust is how a count changes day to day. Add is stock that arrived, and never
 * overwrites what orders took while the modal was open; Set count is what was
 * counted on the shelf. Both go through ApplyStockChanges and its lock. An item
 * nobody counts yet offers only Set count, which is how counting starts.
 */
final class StockActions
{
    private const string ADD = 'add';

    private const string SET = 'set';

    public static function adjust(): Action
    {
        return Action::make('adjustStock')
            ->label(__('panel.stock.adjust'))
            ->iconButton()
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('info')
            ->tooltip(__('panel.stock.adjust'))
            ->authorize('update')
            ->modalHeading(fn (MenuItem $record): string => $record->tracksStock()
                ? (string) __('panel.stock.adjust_heading_counted', ['name' => $record->name, 'count' => $record->stock_quantity])
                : (string) __('panel.stock.adjust_heading', ['name' => $record->name]))
            ->modalWidth(Width::Medium)
            ->modalSubmitActionLabel(__('panel.stock.save'))
            ->fillForm(fn (MenuItem $record): array => ['mode' => $record->tracksStock() ? self::ADD : self::SET])
            ->schema(fn (MenuItem $record): array => [
                ToggleButtons::make('mode')
                    ->hiddenLabel()
                    ->options([
                        self::ADD => __('panel.stock.add'),
                        self::SET => __('panel.stock.set_count'),
                    ])
                    ->grouped()
                    ->required()
                    ->live()
                    ->visible($record->tracksStock()),

                TextInput::make('quantity')
                    ->label(fn (Get $get): string => (string) ($get('mode') === self::ADD
                        ? __('panel.stock.arrived')
                        : __('panel.stock.counted')))
                    ->integer()
                    ->required()
                    ->minValue(fn (Get $get): int => $get('mode') === self::ADD ? 1 : 0)
                    ->maxValue(99999),

                TextInput::make('note')
                    ->label(__('panel.stock.note'))
                    ->maxLength(120),
            ])
            ->action(function (array $data, MenuItem $record): void {
                $isAdd = ($data['mode'] ?? self::SET) === self::ADD && $record->tracksStock();
                $quantity = (int) $data['quantity'];
                $user = Auth::user();

                app(ApplyStockChanges::class)(
                    [$isAdd
                        ? StockChange::add(MenuItem::class, $record->getKey(), $quantity)
                        : StockChange::setTo(MenuItem::class, $record->getKey(), $quantity)],
                    $isAdd ? StockMovementReason::Restock : StockMovementReason::Count,
                    user: $user instanceof User ? $user : null,
                    note: filled($data['note'] ?? null) ? (string) $data['note'] : null,
                );
            })
            ->successNotificationTitle(__('panel.stock.saved'));
    }

    public static function history(): Action
    {
        return Action::make('stockHistory')
            ->label(__('panel.stock.history'))
            ->iconButton()
            ->icon(Heroicon::OutlinedClock)
            ->color('gray')
            ->tooltip(__('panel.stock.history'))
            ->authorize('view')
            ->modalHeading(fn (MenuItem $record): string => (string) __('panel.stock.history_heading', ['name' => $record->name]))
            ->modalWidth(Width::FourExtraLarge)
            // Nothing to save: this only reads.
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('panel.stock.close'))
            ->schema([
                RepeatableEntry::make('movements')
                    ->hiddenLabel()
                    ->state(fn (MenuItem $record): array => self::movementsOf($record))
                    ->placeholder(__('panel.stock.no_history'))
                    ->table([
                        TableColumn::make(__('panel.stock.when')),
                        TableColumn::make(__('panel.stock.reason')),
                        TableColumn::make(__('panel.stock.change'))->alignment(Alignment::End),
                        TableColumn::make(__('panel.stock.after'))->alignment(Alignment::End),
                        TableColumn::make(__('panel.stock.by')),
                        TableColumn::make(__('panel.stock.note')),
                    ])
                    ->schema([
                        TextEntry::make('when')->dateTime(),
                        TextEntry::make('reason')
                            ->badge()
                            ->formatStateUsing(fn (StockMovementReason $state): string => $state->label())
                            ->color(fn (StockMovementReason $state): string => $state->color()),
                        TextEntry::make('change'),
                        TextEntry::make('after'),
                        TextEntry::make('by')->placeholder('—'),
                        TextEntry::make('note')->placeholder('—'),
                    ]),
            ]);
    }

    /**
     * An item's fifty newest count changes, as the history table reads them.
     *
     * @return list<array{when: CarbonImmutable|null, reason: StockMovementReason, change: string, after: int, by: string|null, note: string|null}>
     */
    private static function movementsOf(MenuItem $record): array
    {
        $itemId = $record->getKey();

        // once(): Filament reads the entry's state more than once while it draws the modal.
        // array_values(): a collection's all() is keyed, and this reads as a list.
        return once(fn (): array => array_values(StockMovement::query()
            ->where('menu_item_id', $itemId)
            // A member of the product team who changed a count is on no roster,
            // so the panel's tenancy scope on users would lose their name.
            ->with(['user' => fn ($user) => $user->withoutGlobalScopes()->select(['id', 'name'])])
            ->latest('id')
            ->limit(50)
            ->get(['id', 'reason', 'quantity_change', 'quantity_after', 'order_id', 'user_id', 'note', 'created_at'])
            ->map(fn (StockMovement $movement): array => [
                'when' => $movement->created_at,
                'reason' => $movement->reason,
                'change' => ($movement->quantity_change > 0 ? '+' : '').$movement->quantity_change,
                'after' => $movement->quantity_after,
                'by' => $movement->order_id !== null
                    ? (string) __('panel.stock.order_reference', ['number' => $movement->order_id])
                    : $movement->user?->name,
                'note' => $movement->note,
            ])
            ->all()));
    }
}
