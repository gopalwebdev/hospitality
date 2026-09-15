<?php

namespace App\Filament\Tenant\Resources\Orders\Tables;

use App\Actions\Orders\CancelOrder;
use App\Enums\OrderStatus;
use App\Filament\Schemas\PricingFields;
use App\Models\Order;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        // Resolved once for the page rather than per row: every order here
        // belongs to the same tenant and was priced in its currency.
        $currency = PricingFields::currency();

        return $table
            ->columns([
                TextColumn::make('id')
                    ->label(__('panel.orders.number'))
                    ->formatStateUsing(fn (int $state): string => '#'.$state)
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label(__('panel.orders.placed_at'))
                    ->dateTime()
                    ->description(fn (Order $record): ?string => $record->created_at?->diffForHumans())
                    ->sortable(),

                TextColumn::make('location_label')
                    ->label(__('panel.orders.location'))
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('menu.name')
                    ->label(__('panel.categories.menu'))
                    ->icon(Heroicon::OutlinedBookOpen)
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),

                TextColumn::make('lines_count')
                    ->label(__('panel.orders.lines'))
                    ->counts('lines')
                    ->alignEnd(),

                TextColumn::make('total_minor_units')
                    ->label(__('panel.orders.total'))
                    ->formatStateUsing(fn (int $state): string => $currency->format($state))
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('status')
                    ->label(__('panel.orders.status'))
                    ->badge()
                    ->formatStateUsing(fn (OrderStatus $state): string => $state->label())
                    ->color(fn (OrderStatus $state): string => $state->color()),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('panel.orders.status'))
                    ->options(OrderStatus::options()),
            ])
            ->recordActions([
                ViewAction::make()
                    ->iconButton()
                    ->icon(Heroicon::OutlinedEye)
                    ->tooltip(__('panel.orders.view')),

                self::cancelAction()
                    ->iconButton()
                    ->tooltip(__('panel.orders.cancel')),
            ])
            ->defaultSort('id', 'desc')
            // Every row names its menu, so the menus are loaded once for the page.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'menu' => fn ($menu) => $menu->select(['id', 'name']),
            ]))
            ->emptyStateHeading(__('panel.orders.empty_heading'))
            ->emptyStateDescription(__('panel.orders.empty_description'))
            ->emptyStateIcon(Heroicon::OutlinedShoppingBag);
    }

    /**
     * Call a placed order off and put back what it took from stock — from a row, or from the order's own page.
     */
    public static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label(__('panel.orders.cancel'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->authorize('cancel')
            ->visible(fn (Order $record): bool => $record->isPlaced())
            ->requiresConfirmation()
            ->modalHeading(fn (Order $record): string => (string) __('panel.orders.cancel_heading', ['number' => $record->getKey()]))
            ->modalDescription(__('panel.orders.cancel_warning'))
            ->modalSubmitActionLabel(__('panel.orders.cancel'))
            ->action(function (Order $record): void {
                $user = Auth::user();

                app(CancelOrder::class)($record, $user instanceof User ? $user : null);

                // The page redraws from this instance; it has to say cancelled.
                $record->refresh();
            })
            ->successNotificationTitle(__('panel.orders.cancelled'));
    }
}
