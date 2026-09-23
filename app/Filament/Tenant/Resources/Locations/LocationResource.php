<?php

namespace App\Filament\Tenant\Resources\Locations;

use App\Filament\Schemas\TranslatedFields;
use App\Filament\Tenant\Resources\Locations\Pages\ListLocations;
use App\Filament\Tenant\Resources\Locations\Schemas\LocationForm;
use App\Filament\Tenant\Resources\Locations\Tables\LocationsTable;
use App\Models\Location;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Where an order goes: a tenant's rooms, tables and delivery points.
 *
 * One generic module for all three (App\Enums\LocationKind) rather than
 * separate Rooms and Tables resources, offered at order time alongside free
 * text. Locations are few, so they are created and edited in modals on the
 * list — the Charges shape — and a hotel setting up its rooms in bulk uses
 * "Add several" (App\Actions\Locations\CreateLocationRange) instead of adding
 * them one at a time.
 *
 * Scoping is Filament's: the panel has a tenant, so every query here is
 * limited to the tenant in the subdomain and new rows are stamped with it.
 * Who may use the page is LocationPolicy's business, through location.manage.
 */
class LocationResource extends Resource
{
    #[\Override]
    protected static ?string $model = Location::class;

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    #[\Override]
    protected static ?int $navigationSort = 86;

    #[\Override]
    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Labels are methods rather than static properties because a property is
     * evaluated when the class loads, before the request has chosen a language.
     */
    public static function getModelLabel(): string
    {
        return __('panel.locations.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('panel.locations.plural');
    }

    /**
     * The top bar's search looks in the language the panel is showing.
     *
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return TranslatedFields::searchableAttributes('name');
    }

    public static function form(Schema $schema): Schema
    {
        return LocationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LocationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLocations::route('/'),
        ];
    }
}
