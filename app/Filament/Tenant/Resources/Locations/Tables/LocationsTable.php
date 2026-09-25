<?php

namespace App\Filament\Tenant\Resources\Locations\Tables;

use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\SpreadAcrossOrders;
use App\Enums\Currency;
use App\Enums\LocationKind;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Exceptions\PaymentRefused;
use App\Filament\Schemas\PaymentFields;
use App\Filament\Schemas\PricingFields;
use App\Filament\Schemas\TranslatedFields;
use App\Filament\Tables\Reordering;
use App\Filament\Tenant\Resources\Locations\Schemas\LocationForm;
use App\Models\Location;
use App\Models\Order;
use App\Models\PaymentDevice;
use App\Models\Tenant;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use LogicException;

class LocationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('panel.shared.name'))
                    // A translated column holds a JSON document, so searching
                    // and sorting have to name the language they mean.
                    ->searchable(query: fn (Builder $query, string $search): Builder => TranslatedFields::search($query, 'name', $search))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => TranslatedFields::sort($query, 'name', $direction)),

                TextColumn::make('kind')
                    ->label(__('panel.locations.kind'))
                    ->badge()
                    // The same icon the floor's cards carry, so a room reads
                    // as a room wherever it is drawn.
                    ->icon(fn (LocationKind $state): Heroicon => $state->icon())
                    ->formatStateUsing(fn (LocationKind $state): string => $state->label())
                    ->color(fn (LocationKind $state): string => $state->color()),

                TextColumn::make('code')
                    ->label(__('panel.locations.code'))
                    ->placeholder('—'),

                TextColumn::make('capacity')
                    ->label(__('panel.locations.capacity'))
                    ->placeholder('—')
                    ->alignEnd(),

                TextColumn::make('orders_count')
                    ->label(__('panel.locations.orders_count'))
                    ->counts('orders')
                    ->alignEnd(),

                IconColumn::make('is_active')
                    ->label(__('panel.locations.is_active'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->label(__('panel.locations.kind'))
                    ->options(LocationKind::options()),
            ])
            ->recordActions([
                self::settleAction(),

                EditAction::make()
                    ->iconButton()
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->tooltip(__('panel.arrangement.edit'))
                    ->mutateRecordDataUsing(fn (array $data, Location $record): array => LocationForm::fillTranslations($data, $record)),

                DeleteAction::make()
                    ->iconButton()
                    ->icon(Heroicon::OutlinedTrash)
                    ->tooltip(__('panel.arrangement.delete'))
                    ->modalDescription(__('panel.locations.delete_warning')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            // The order a guest is offered locations in, dragged rather than
            // typed — see .ai/rules/tables.md.
            ->reorderable('position')
            ->reorderRecordsTriggerAction(Reordering::trigger())
            ->defaultSort('position')
            ->emptyStateHeading(__('panel.locations.empty_heading'))
            ->emptyStateDescription(__('panel.locations.empty_description'))
            ->emptyStateIcon(Heroicon::OutlinedMapPin);
    }

    /**
     * Check a location out: settle every outstanding placed order against it
     * with one payment, spread oldest first.
     *
     * Authorised against payment.record directly, by a closure, rather than
     * through LocationPolicy: this is a payments capability shown on a
     * location row, not a location capability, and LocationPolicy has no
     * business naming a payment permission. Nothing to invent on
     * LocationPolicy for it.
     */
    public static function settleAction(): Action
    {
        // Resolved once for the page rather than per row.
        $currency = PricingFields::currency();

        return Action::make('settle')
            ->label(__('panel.orders.settle'))
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->authorize(fn (): bool => (bool) Auth::user()?->can(Permission::PaymentRecord->value))
            ->visible(fn (Location $record): bool => self::outstandingOrders($record)->isNotEmpty())
            ->modalHeading(fn (Location $record): string => (string) __('panel.locations.settle_heading', ['name' => $record->name]))
            ->schema(fn (Location $record): array => [
                Select::make('orders')
                    ->label(__('panel.orders.settle_orders'))
                    ->multiple()
                    ->options(fn (): array => self::orderOptions($record, $currency))
                    ->default(fn (): array => self::outstandingOrders($record)
                        ->map(fn (Order $order): int => $order->getKey())
                        ->all())
                    ->required()
                    ->native(false),

                PaymentFields::method(),
                PaymentFields::device(self::tenant()),
                PaymentFields::reference(),
                PaymentFields::amount($currency, fn (): float => $currency->toMajorUnits(
                    self::outstandingOrders($record)->sum(fn (Order $order): int => $order->amountOutstanding()),
                )),
                PaymentFields::note(),
            ])
            ->action(function (array $data) use ($currency): void {
                $method = PaymentMethod::from((string) $data['method']);
                $amount = $currency->toMinorUnits($data['amount']);
                $device = filled($data['payment_device_id'] ?? null)
                    ? PaymentDevice::query()->find((int) $data['payment_device_id'])
                    : null;
                $user = Auth::user();

                $orderIds = array_map(intval(...), $data['orders'] ?? []);

                $orders = Order::query()
                    ->whereKey($orderIds)
                    ->oldest('id')
                    ->get();

                $allocations = app(SpreadAcrossOrders::class)($amount, $orders);

                try {
                    app(RecordPayment::class)(
                        self::tenant(),
                        $method,
                        $amount,
                        $allocations,
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

                Notification::make()
                    ->title(__('panel.orders.payment_recorded'))
                    ->success()
                    ->send();
            });
    }

    /**
     * The panel's current tenant.
     */
    private static function tenant(): Tenant
    {
        $tenant = Filament::getTenant();

        throw_unless($tenant instanceof Tenant, LogicException::class, 'Settling a location requires a tenant.');

        return $tenant;
    }

    /**
     * This location's own placed orders that still owe money, oldest first.
     *
     * Order::scopeUnsettled() does not exclude a cancelled order, so status is
     * named explicitly here too — a cancelled order that was never paid must
     * not be offered for settling.
     *
     * The amount each owes is read from a withSum loaded here rather than a
     * fresh query per order, and once() caches this per location: both the
     * orders select's options and its default, and the amount field's
     * default, ask for the same list while one modal is built.
     *
     * @return Collection<int, Order>
     */
    private static function outstandingOrders(Location $location): Collection
    {
        return once(fn (): Collection => Order::query()
            ->where('location_id', $location->getKey())
            ->live()
            ->unsettled()
            ->withSum(['paymentAllocations as amount_paid' => fn ($allocations) => $allocations
                ->whereHas('payment', fn ($payment) => $payment->live())], 'amount')
            ->oldest('id')
            ->get());
    }

    /**
     * Each outstanding order, labelled with its number and what it still owes.
     *
     * @return array<int, string>
     */
    private static function orderOptions(Location $location, Currency $currency): array
    {
        return self::outstandingOrders($location)
            ->mapWithKeys(fn (Order $order): array => [
                $order->getKey() => (string) __('panel.orders.settle_order_option', [
                    'number' => $order->getKey(),
                    'amount' => $currency->format($order->amountOutstanding()),
                ]),
            ])
            ->all();
    }
}
