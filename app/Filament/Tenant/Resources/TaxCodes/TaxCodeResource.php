<?php

namespace App\Filament\Tenant\Resources\TaxCodes;

use App\Filament\Tenant\Resources\TaxCodes\Pages\ListTaxCodes;
use App\Filament\Tenant\Resources\TaxCodes\Schemas\TaxCodeForm;
use App\Filament\Tenant\Resources\TaxCodes\Tables\TaxCodesTable;
use App\Models\TaxCode;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The HSN and SAC codes an item can be filed under, and the GST each carries.
 *
 * It is here so nobody types a rate per item from memory. The item form's
 * picker reads this list and copies a code's rate onto the item; nothing on a
 * bill reads this table (`App\Models\TaxCode`).
 *
 * **Scoping is not Filament's here, and that is deliberate.** The panel's own
 * tenancy would add `where tenant_id = ...` and hide the catalogue, which is
 * exactly the half of this list a new tenant needs. So `$isScopedToTenant` is
 * off, `getEloquentQuery()` asks for the catalogue *and* this tenant's own
 * rows through `TaxCode::availableTo()`, and a row created here is stamped
 * with the panel's tenant by hand. `TaxCodePolicy` is what keeps a tenant from
 * editing a catalogue row it can see.
 */
class TaxCodeResource extends Resource
{
    #[\Override]
    protected static ?string $model = TaxCode::class;

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    /**
     * Filament's tenancy would hide the shared catalogue; getEloquentQuery() scopes instead.
     */
    #[\Override]
    protected static bool $isScopedToTenant = false;

    /**
     * Beside Charges and Settings, which the same people keep.
     */
    #[\Override]
    protected static ?int $navigationSort = 86;

    #[\Override]
    protected static ?string $recordTitleAttribute = 'code';

    public static function getModelLabel(): string
    {
        return __('panel.tax_codes.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('panel.tax_codes.plural');
    }

    /**
     * The catalogue plus this tenant's own, never another tenant's.
     *
     * @return Builder<TaxCode>
     */
    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();

        $query = parent::getEloquentQuery();

        return $tenant instanceof Model
            // @phpstan-ignore-next-line argument.type
            ? $query->availableTo($tenant)
            : $query->whereNull('tenant_id');
    }

    public static function form(Schema $schema): Schema
    {
        return TaxCodeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TaxCodesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTaxCodes::route('/'),
        ];
    }
}
