<?php

namespace App\Filament\Tenant\Resources\Charges;

use App\Filament\Schemas\TranslatedFields;
use App\Filament\Tenant\Resources\Charges\Pages\ListCharges;
use App\Filament\Tenant\Resources\Charges\Schemas\ChargeForm;
use App\Filament\Tenant\Resources\Charges\Tables\ChargesTable;
use App\Models\Charge;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * What this tenant adds to a guest's bill beyond the price: a service charge, a
 * packing charge, a room-service fee.
 *
 * Its own page rather than a section of Settings, because a tenant may levy any
 * number of them and each belongs on some menus and not others — a packing
 * charge on the room-service card and not on the housekeeping one. Charges are
 * few, so they are created and edited in modals on the list, and dragged into
 * the order a guest reads them.
 *
 * Scoping is Filament's: the panel has a tenant, so every query here is limited
 * to the tenant in the subdomain and new rows are stamped with it. Who may use
 * the page is ChargePolicy's business, through settings.manage.
 */
class ChargeResource extends Resource
{
    #[\Override]
    protected static ?string $model = Charge::class;

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    /**
     * Beside Settings, where charges used to live.
     */
    #[\Override]
    protected static ?int $navigationSort = 85;

    #[\Override]
    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Labels are methods rather than static properties because a property is
     * evaluated when the class loads, before the request has chosen a language.
     */
    public static function getModelLabel(): string
    {
        return __('panel.charges.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('panel.charges.plural');
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
        return ChargeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ChargesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCharges::route('/'),
        ];
    }
}
