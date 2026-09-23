<?php

namespace App\Filament\Tenant\Resources\Locations\Schemas;

use App\Enums\LocationKind;
use App\Filament\Schemas\TranslatedFields;
use App\Models\Location;
use App\Models\Tenant;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

/**
 * What a location is, and what a guest reads when it is offered to them.
 *
 * One compact section, the ChargeForm shape: a tenant sets these up a
 * handful at a time, or many at once through CreateLocationRange, never one
 * long form per row.
 */
class LocationForm
{
    /**
     * The columns this form edits in more than one language.
     *
     * @var list<string>
     */
    public const array TRANSLATED = ['name'];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TranslatedFields::localeSwitcher(),

                Section::make(__('panel.locations.section'))
                    ->icon(Heroicon::OutlinedMapPin)
                    ->compact()
                    ->schema([
                        // Only this tenant's type offers, preselected to its
                        // default — plus whatever kind the record already
                        // holds, so a row survives its tenant's type
                        // changing later (App\Enums\TenantType::locationKinds()).
                        Select::make('kind')
                            ->label(__('panel.locations.kind'))
                            ->options(fn (?Location $record): array => self::kindOptions($record))
                            ->default(fn (): ?string => self::tenant()?->type->defaultLocationKind()->value)
                            ->required()
                            ->native(false),

                        ...TranslatedFields::text(
                            'name',
                            __('panel.shared.name'),
                            maxLength: 64,
                            // Unique within the tenant: two locations sharing
                            // a name would read to a guest as the same place.
                            uniqueWithin: fn (): Builder => Location::query()
                                ->where('tenant_id', Filament::getTenant()?->getKey()),
                            uniqueMessage: __('panel.locations.unique'),
                        ),

                        TextInput::make('code')
                            ->label(__('panel.locations.code'))
                            ->maxLength(16),

                        TextInput::make('capacity')
                            ->label(__('panel.locations.capacity'))
                            ->integer()
                            ->minValue(1)
                            ->maxValue(32767),

                        Toggle::make('is_active')
                            ->label(__('panel.locations.is_active'))
                            ->default(true)
                            ->inline(false),
                    ]),
            ]);
    }

    /**
     * Put every language back into the form when a location is edited.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillTranslations(array $data, Location $record): array
    {
        return $record->fillTranslationsInto($data, ...self::TRANSLATED);
    }

    /**
     * This tenant's type decides which kinds a new location may take
     * (App\Enums\TenantType::locationKinds()) — plus whatever kind the
     * record being edited already holds, so a row survives its tenant's
     * type changing after it was created.
     *
     * once(): Filament asks a select for its options several times while
     * building and validating one form, and this repo's duplicate-query
     * guard throws otherwise.
     *
     * @return array<string, string>
     */
    private static function kindOptions(?Location $record): array
    {
        return once(function () use ($record): array {
            $tenant = self::tenant();
            $options = $tenant instanceof Tenant ? $tenant->type->locationKindOptions() : LocationKind::options();

            if ($record?->exists === true && ! array_key_exists($record->kind->value, $options)) {
                $options[$record->kind->value] = $record->kind->label();
            }

            return $options;
        });
    }

    /**
     * The panel's current tenant.
     */
    private static function tenant(): ?Tenant
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Tenant ? $tenant : null;
    }
}
