<?php

namespace App\Filament\Tenant\Resources\Orders\Tables;

use App\Actions\Orders\AcceptOrder;
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
use App\Filament\Tenant\Pages\TakeOrder;
use App\Filament\Tenant\Resources\Orders\OrderResource;
use App\Filament\Tenant\Resources\Orders\Schemas\OrderInfolist;
use App\Models\Location;
use App\Models\Order;
use App\Models\PaymentDevice;
use App\Models\Tenant;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
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
                self::viewAction()
                    ->iconButton()
                    ->tooltip(__('panel.orders.view')),

                self::acceptAction()
                    ->iconButton()
                    ->tooltip(__('panel.orders.accept')),

                self::changeAction()
                    ->iconButton()
                    ->tooltip(__('panel.orders.change')),

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
     * Read one order — in a modal, from anywhere that can name it.
     *
     * There is no order page any more: the project owner asked for the whole
     * record in a modal, and a full page for four short sections was a
     * navigation away and back for something staff glance at. The modal is wide
     * because the lines and the payments are tables, and it is read-only —
     * taking a payment and calling the order off are the buttons beside it.
     *
     * mountUsing() is what makes it safe: the list query carries no lines,
     * choices, charges or payments, so they are loaded onto the record the
     * moment the modal opens rather than lazily while it renders, which
     * Model::shouldBeStrict() would refuse.
     */
    public static function viewAction(): ViewAction
    {
        return ViewAction::make()
            ->icon(Heroicon::OutlinedEye)
            ->modalHeading(fn (Order $record): string => (string) __('panel.orders.view_heading', ['number' => $record->getKey()]))
            ->modalWidth(Width::FiveExtraLarge)
            ->mountUsing(fn (Order $record) => OrderResource::loadForView($record))
            ->schema(fn (Schema $schema): Schema => OrderInfolist::configure($schema));
    }

    /**
     * Pick an order up: the kitchen has it now.
     *
     * Offered only while it is still waiting, and it is the one thing that
     * takes an order out of reach of Change — which is the point of it.
     */
    public static function acceptAction(): Action
    {
        return Action::make('accept')
            ->label(__('panel.orders.accept'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorize('accept')
            ->visible(fn (Order $record): bool => $record->isPlaced())
            ->requiresConfirmation()
            ->modalHeading(fn (Order $record): string => (string) __('panel.orders.accept_heading', ['number' => $record->getKey()]))
            ->modalDescription(__('panel.orders.accept_warning'))
            ->modalSubmitActionLabel(__('panel.orders.accept'))
            ->action(function (Order $record): void {
                app(AcceptOrder::class)($record);

                self::reloadPayments($record);
            })
            ->successNotificationTitle(__('panel.orders.accepted'));
    }

    /**
     * Change what is on an order nobody has picked up yet.
     *
     * A link to the counter with the order loaded into its basket, rather than
     * a form: changing an order is re-taking it, priced by the same
     * PriceBasket, and TakeOrder is where that already happens. It disappears
     * the moment the order is accepted or a payment is taken against it
     * (Order::canBeChanged()), which is the whole rule the project owner asked
     * for made visible.
     */
    public static function changeAction(): Action
    {
        return Action::make('change')
            ->label(__('panel.orders.change'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('warning')
            ->authorize('change')
            ->visible(fn (Order $record): bool => $record->canBeChanged())
            ->url(fn (Order $record): string => TakeOrder::getUrl().'?order='.$record->getKey());
    }

    /**
     * Call a live order off and put back what it was holding — from a row, or from the modal reading it.
     */
    public static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label(__('panel.orders.cancel'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->authorize('cancel')
            ->visible(fn (Order $record): bool => $record->isLive())
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
                // dot-path eager load and any withSum aggregate. The view modal's
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
            ->visible(fn (Order $record): bool => $record->isLive() && $record->amountOutstanding() > 0)
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

        // Only the payments: the lines and charges on a record the modal
        // already loaded have not changed, and a record from the list has
        // none loaded to keep.
        $record->load(array_intersect_key(
            OrderResource::viewRelations(),
            array_flip([
                'paymentAllocations',
                'paymentAllocations.payment.paymentDevice',
                'paymentAllocations.payment.recordedBy',
            ]),
        ));
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
        return $query->live()->unsettled();
    }

    /**
     * This tenant's locations, for the filter dropdown.
     *
     * Not narrowed to the active ones: this filters orders already placed,
     * and one may name a location switched off since.
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

            ->inReadingOrder()
            ->get()
            ->mapWithKeys(fn (Location $location): array => [$location->getKey() => $location->name])
            ->all());
    }
}
