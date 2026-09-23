<?php

namespace App\Filament\Tenant\Resources\Locations\Pages;

use App\Actions\Locations\CreateLocationRange;
use App\Enums\LocationKind;
use App\Filament\Tenant\Resources\Locations\LocationResource;
use App\Models\Location;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Every room, table and delivery point this tenant has set up.
 */
class ListLocations extends ListRecords
{
    #[\Override]
    protected static string $resource = LocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('panel.locations.create'))
                ->icon(Heroicon::OutlinedPlus),

            $this->addSeveralAction(),
        ];
    }

    /**
     * All, then one tab per kind **this tenant may actually have**.
     *
     * Built from TenantType::locationKinds() rather than every case of the
     * enum, so a hotel does not read a permanently empty "Table 0" tab and a
     * restaurant does not read "Room 0" — which is exactly what iterating the
     * cases produced. A kind added to the enum still cannot leave a tab
     * behind, because the list it iterates is the enum's own answer.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $tabs = [
            'all' => Tab::make(__('panel.locations.all_tab'))
                ->icon(Heroicon::OutlinedRectangleStack)
                ->badge(fn (): int => array_sum($this->counts()))
                ->deferBadge(),
        ];

        foreach ($this->tenant()->type->locationKinds() as $kind) {
            $tabs[$kind->value] = Tab::make($kind->label())
                ->icon($kind->icon())
                ->badge(fn (): int => $this->counts()[$kind->value] ?? 0)
                ->deferBadge()
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('kind', $kind));
        }

        return $tabs;
    }

    /**
     * "Room", 101 to 120, in one go — for a tenant that does not want to add
     * every room by hand.
     *
     * CreateLocationRange refuses a blank prefix, a backwards range or one
     * over its limit with a LogicException. That is surfaced as a danger
     * notification rather than left to become a 500, so whoever is adding
     * rooms can fix the range and try again.
     */
    private function addSeveralAction(): Action
    {
        return Action::make('addSeveral')
            ->label(__('panel.locations.add_several'))
            ->icon(Heroicon::OutlinedNumberedList)
            ->schema([
                // Only this tenant's type offers — a hotel is never offered
                // "Table" here either (App\Enums\TenantType::locationKinds()).
                Select::make('kind')
                    ->label(__('panel.locations.kind'))
                    ->options(fn (): array => $this->tenant()->type->locationKindOptions())
                    ->required()
                    ->native(false),

                TextInput::make('name_prefix')
                    ->label(__('panel.locations.name_prefix'))
                    ->required()
                    ->maxLength(64),

                TextInput::make('from')
                    ->label(__('panel.locations.range_from'))
                    ->numeric()
                    ->minValue(1)
                    ->required(),

                TextInput::make('to')
                    ->label(__('panel.locations.range_to'))
                    ->numeric()
                    ->minValue(1)
                    ->required(),
            ])
            ->action(function (array $data): void {
                try {
                    $created = app(CreateLocationRange::class)(
                        $this->tenant(),
                        LocationKind::from((string) $data['kind']),
                        (string) $data['name_prefix'],
                        (int) $data['from'],
                        (int) $data['to'],
                    );
                } catch (LogicException $exception) {
                    Notification::make()
                        ->title($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(trans_choice('panel.locations.range_created', $created, ['count' => $created]))
                    ->success()
                    ->send();

                $this->resetTable();
            });
    }

    /**
     * How many locations of each kind this tenant has, one query for every tab's badge.
     *
     * Keyed by the kind's own stored value rather than a hardcoded list of
     * columns, so a case added to LocationKind later is counted without this
     * changing. Memoized because every tab's badge asks it separately.
     *
     * @return array<string, int>
     */
    private function counts(): array
    {
        return once(fn (): array => Location::query()
            ->toBase()
            ->selectRaw('kind, count(*) as aggregate')
            ->groupBy('kind')
            ->pluck('aggregate', 'kind')
            ->map(fn (int|string $count): int => (int) $count)
            ->all());
    }

    /**
     * The tenant the panel is currently serving.
     */
    private function tenant(): Tenant
    {
        $tenant = Filament::getTenant();

        throw_unless($tenant instanceof Tenant, LogicException::class, 'The locations page requires a tenant.');

        return $tenant;
    }
}
