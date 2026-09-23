<?php

namespace App\Filament\Tenant\Resources\Payments\Tables;

use App\Actions\Payments\VoidPayment;
use App\Enums\PaymentMethod;
use App\Filament\Schemas\PricingFields;
use App\Models\Payment;
use App\Models\PaymentDevice;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use LogicException;

class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        // Resolved once for the page rather than per row: every payment here
        // belongs to the same tenant and was taken in its currency.
        $currency = PricingFields::currency();

        return $table
            ->columns([
                TextColumn::make('paid_at')
                    ->label(__('panel.payments.paid_at'))
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('method')
                    ->label(__('panel.payments.method'))
                    ->badge()
                    ->formatStateUsing(fn (PaymentMethod $state): string => $state->label())
                    ->color(fn (PaymentMethod $state): string => $state->color()),

                TextColumn::make('paymentDevice.name')
                    ->label(__('panel.payments.device'))
                    ->placeholder('—'),

                TextColumn::make('amount')
                    ->label(__('panel.payments.amount'))
                    ->formatStateUsing(fn (int $state): string => $currency->format($state))
                    // A voided payment is never live money: greyed out and
                    // badged, rather than reading the same as anything still
                    // owed against.
                    ->color(fn (Payment $record): ?string => $record->isVoided() ? 'gray' : null)
                    ->weight(fn (Payment $record): ?FontWeight => $record->isVoided() ? null : FontWeight::Bold)
                    ->badge(fn (Payment $record): bool => $record->isVoided())
                    ->alignEnd(),

                TextColumn::make('reference')
                    ->label(__('panel.payments.reference'))
                    ->placeholder('—'),

                TextColumn::make('orders.id')
                    ->label(__('panel.payments.orders'))
                    ->formatStateUsing(fn (int $state): string => '#'.$state)
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),

                TextColumn::make('recordedBy.name')
                    ->label(__('panel.payments.recorded_by'))
                    ->placeholder('—'),

                IconColumn::make('voided')
                    ->label(__('panel.payments.voided'))
                    ->state(fn (Payment $record): bool => $record->isVoided())
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('method')
                    ->label(__('panel.payments.method'))
                    ->options(PaymentMethod::options()),

                SelectFilter::make('payment_device_id')
                    ->label(__('panel.payments.device'))
                    ->options(fn (): array => self::deviceOptions()),

                TernaryFilter::make('voided')
                    ->label(__('panel.payments.voided'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('voided_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('voided_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),

                Filter::make('paid_at')
                    ->label(__('panel.payments.paid_at'))
                    ->schema([
                        DatePicker::make('paid_from')
                            ->label(__('panel.payments.paid_from')),

                        DatePicker::make('paid_until')
                            ->label(__('panel.payments.paid_until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            $data['paid_from'] ?? null,
                            fn (Builder $dated, string $date): Builder => $dated->whereDate('paid_at', '>=', $date),
                        )
                        ->when(
                            $data['paid_until'] ?? null,
                            fn (Builder $dated, string $date): Builder => $dated->whereDate('paid_at', '<=', $date),
                        )),
            ])
            ->recordActions([
                ViewAction::make()
                    ->iconButton()
                    ->icon(Heroicon::OutlinedEye)
                    ->tooltip(__('panel.payments.view')),

                self::voidAction()
                    ->iconButton()
                    ->tooltip(__('panel.payments.void')),
            ])
            ->defaultSort('paid_at', 'desc')
            // Every row names its device, who recorded it and the orders it
            // settled, so they are loaded once for the page.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'paymentDevice:id,name',
                'recordedBy:id,name',
                'orders:id',
            ]))
            ->emptyStateHeading(__('panel.payments.empty_heading'))
            ->emptyStateDescription(__('panel.payments.empty_description'))
            ->emptyStateIcon(Heroicon::OutlinedBanknotes);
    }

    /**
     * Reverse a payment already recorded — from the list, or from its own page.
     */
    public static function voidAction(): Action
    {
        return Action::make('void')
            ->label(__('panel.payments.void'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorize('void')
            ->visible(fn (Payment $record): bool => ! $record->isVoided())
            ->requiresConfirmation()
            ->modalHeading(fn (Payment $record): string => (string) __('panel.payments.void_heading', ['number' => $record->getKey()]))
            ->modalDescription(__('panel.payments.void_warning'))
            ->modalSubmitActionLabel(__('panel.payments.void'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('panel.payments.void_reason'))
                    ->maxLength(200),
            ])
            ->action(function (array $data, Payment $record): void {
                $user = Auth::user();

                try {
                    app(VoidPayment::class)(
                        $record,
                        filled($data['reason'] ?? null) ? (string) $data['reason'] : null,
                        $user instanceof User ? $user : null,
                    );
                } catch (LogicException $exception) {
                    Notification::make()
                        ->title($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                // Attributes only, deliberately not a plain refresh(): this
                // page may already have allocations.order eager loaded (the
                // infolist reads it), and refresh() reloads a bare relation
                // without its nested one, which would lazy-load under strict
                // mode the moment the page redraws. Voiding touches only the
                // three columns below, so nothing else needs to move.
                $record->setRawAttributes(
                    Payment::query()->whereKey($record->getKey())->firstOrFail()->getAttributes(),
                );

                Notification::make()
                    ->title(__('panel.payments.voided_notification'))
                    ->success()
                    ->send();
            });
    }

    /**
     * This tenant's devices, for the filter dropdown.
     *
     * once(): Filament asks a filter for its options more than once while it
     * builds the table.
     *
     * @return array<int, string>
     */
    private static function deviceOptions(): array
    {
        $tenantId = Filament::getTenant()?->getKey();

        return once(fn (): array => PaymentDevice::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('position')
            ->pluck('name', 'id')
            ->all());
    }
}
