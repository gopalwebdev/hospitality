<?php

namespace App\Filament\Tenant\Resources\Orders\Tables;

use App\Actions\Orders\CancelOrder;
use App\Actions\Payments\RecordPayment;
use App\Enums\OrderSettlement;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentState;
use App\Exceptions\PaymentRefused;
use App\Filament\Schemas\PaymentFields;
use App\Filament\Schemas\PricingFields;
use App\Filament\Schemas\TranslatedFields;
use App\Models\Location;
use App\Models\Order;
use App\Models\PaymentDevice;
use App\Models\Tenant;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use LogicException;

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

                TextColumn::make('location_name')
                    ->label(__('panel.orders.location'))
                    ->placeholder('—')
                    ->searchable(query: fn (Builder $query, string $search): Builder => TranslatedFields::search($query, 'location_name', $search)),

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

                TextColumn::make('total')
                    ->label(__('panel.orders.total'))
                    ->formatStateUsing(fn (int $state): string => $currency->format($state))
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('status')
                    ->label(__('panel.orders.status'))
                    ->badge()
                    ->formatStateUsing(fn (OrderStatus $state): string => $state->label())
                    ->color(fn (OrderStatus $state): string => $state->color()),

                TextColumn::make('settlement')
                    ->label(__('panel.orders.settlement'))
                    ->badge()
                    ->formatStateUsing(fn (OrderSettlement $state): string => $state->label())
                    ->color(fn (OrderSettlement $state): string => $state->color()),

                TextColumn::make('payment_state')
                    ->label(__('panel.orders.payment'))
                    ->state(fn (Order $record): PaymentState => $record->paymentState())
                    ->badge()
                    ->formatStateUsing(fn (PaymentState $state): string => $state->label())
                    ->color(fn (PaymentState $state): string => $state->color()),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('panel.orders.status'))
                    ->options(OrderStatus::options()),

                SelectFilter::make('location_id')
                    ->label(__('panel.orders.location'))
                    ->options(fn (): array => self::locationOptions()),

                SelectFilter::make('settlement')
                    ->label(__('panel.orders.settlement'))
                    ->options(OrderSettlement::options()),

                // Unsettled deliberately also asks for a placed order: Order::scopeUnsettled()
                // does not exclude a cancelled one, and a cancelled order that
                // was never paid would otherwise read as needing to be settled.
                Filter::make('unsettled')
                    ->label(__('panel.orders.unsettled_only'))
                    ->query(self::unsettledQuery(...)),
            ])
            ->recordActions([
                ViewAction::make()
                    ->iconButton()
                    ->icon(Heroicon::OutlinedEye)
                    ->tooltip(__('panel.orders.view')),

                self::recordPaymentAction()
                    ->iconButton()
                    ->tooltip(__('panel.orders.record_payment')),

                self::cancelAction()
                    ->iconButton()
                    ->tooltip(__('panel.orders.cancel')),
            ])
            ->defaultSort('id', 'desc')
            // Every row names its menu and reads its own payment badge, so
            // both are resolved once for the page rather than per row. The
            // sum has to be aliased exactly amount_paid and constrained to
            // live payments — Order::amountPaid() reads it back under that
            // name — or the badge reads a wrong number with no error.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['menu' => fn ($menu) => $menu->select(['id', 'name'])])
                ->withSum(['paymentAllocations as amount_paid' => fn ($allocations) => $allocations
                    ->whereHas('payment', fn ($payment) => $payment->live())], 'amount'))
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
                // Deliberately not a plain refresh(): Eloquent's refresh() only
                // reloads bare top-level relation names, dropping a nested
                // dot-path eager load and any withSum aggregate. ViewOrder's
                // route-binding query loads paymentAllocations.payment.paymentDevice
                // and an amount_paid sum, so cancelling from the order's own
                // page would otherwise reload them bare and the infolist's
                // re-render would lazy-load under strict mode.
                self::reloadPayments($record);
            })
            ->successNotificationTitle(__('panel.orders.cancelled'));
    }

    /**
     * Record what was taken against a placed order still owing money — from a row, or from the order's own page.
     */
    public static function recordPaymentAction(): Action
    {
        // Resolved once for the page rather than per row.
        $currency = PricingFields::currency();

        return Action::make('recordPayment')
            ->label(__('panel.orders.record_payment'))
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->authorize('recordPayment')
            ->visible(fn (Order $record): bool => $record->isPlaced() && $record->amountOutstanding() > 0)
            ->schema(fn (Order $record): array => [
                PaymentFields::method(),
                PaymentFields::device(self::tenant()),
                PaymentFields::reference(),
                PaymentFields::amount($currency, fn (): float => $currency->toMajorUnits($record->amountOutstanding())),
                PaymentFields::note(),
            ])
            ->action(function (array $data, Order $record) use ($currency): void {
                $method = PaymentMethod::from((string) $data['method']);
                $amount = $currency->toMinorUnits($data['amount']);
                $device = filled($data['payment_device_id'] ?? null)
                    ? PaymentDevice::query()->find((int) $data['payment_device_id'])
                    : null;
                $user = Auth::user();

                try {
                    app(RecordPayment::class)(
                        self::tenant(),
                        $method,
                        $amount,
                        [$record->getKey() => $amount],
                        $device,
                        filled($data['reference'] ?? null) ? (string) $data['reference'] : null,
                        filled($data['note'] ?? null) ? (string) $data['note'] : null,
                        $user instanceof User ? $user : null,
                    );
                } catch (PaymentRefused $exception) {
                    Notification::make()
                        ->title($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                // The page redraws from this instance; it has to read the new
                // payment state. A plain refresh() would drop the loaded
                // amount_paid sum and reload paymentAllocations without its
                // nested payment — both of which the order's own page reads
                // straight after this — so both are put back deliberately
                // rather than left to a lazy load under strict mode.
                self::reloadPayments($record);

                Notification::make()
                    ->title(__('panel.orders.payment_recorded'))
                    ->success()
                    ->send();
            });
    }

    /**
     * The panel's current tenant. Orders here are already scoped to it, so
     * this never disagrees with the order's own tenant_id, and reading it
     * this way costs no query — unlike $record->tenant, which is not eager
     * loaded here and would lazy-load under strict mode.
     */
    private static function tenant(): Tenant
    {
        $tenant = Filament::getTenant();

        throw_unless($tenant instanceof Tenant, LogicException::class, 'Recording a payment requires a tenant.');

        return $tenant;
    }

    /**
     * Put a just-changed order's payment state back onto the instance the
     * page already holds, the same shape OrderResource loads a record with.
     */
    private static function reloadPayments(Order $record): void
    {
        $fresh = Order::query()
            ->whereKey($record->getKey())
            ->withSum(['paymentAllocations as amount_paid' => fn ($allocations) => $allocations
                ->whereHas('payment', fn ($payment) => $payment->live())], 'amount')
            ->firstOrFail();

        $record->setRawAttributes($fresh->getAttributes());

        $record->load([
            'paymentAllocations' => fn ($allocations) => $allocations->orderByDesc('id'),
            'paymentAllocations.payment.paymentDevice',
            'paymentAllocations.payment.recordedBy',
        ]);
    }

    /**
     * Placed and still owing money. A named method rather than an inline
     * closure, so its own @param carries the generic Order type Order::unsettled()
     * needs — an arrow function typed only as the bare Builder cannot resolve
     * a model's local scope.
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    private static function unsettledQuery(Builder $query): Builder
    {
        return $query->where('status', OrderStatus::Placed->value)->unsettled();
    }

    /**
     * This tenant's locations, for the filter dropdown.
     *
     * deliverable(): a Zone is never picked on an order, so no order can
     * ever be filtered by one — offering it would be a dead option.
     *
     * once(): Filament asks a filter for its options more than once while it
     * builds the table.
     *
     * @return array<int, string>
     */
    private static function locationOptions(): array
    {
        $tenantId = Filament::getTenant()?->getKey();

        return once(fn (): array => Location::query()
            ->where('tenant_id', $tenantId)
            ->deliverable()
            ->inReadingOrder()
            ->get()
            ->mapWithKeys(fn (Location $location): array => [$location->getKey() => $location->name])
            ->all());
    }
}
