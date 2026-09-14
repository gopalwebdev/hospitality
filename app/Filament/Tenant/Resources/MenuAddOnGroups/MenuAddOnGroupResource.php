<?php

namespace App\Filament\Tenant\Resources\MenuAddOnGroups;

use App\Filament\Schemas\TranslatedFields;
use App\Filament\Tenant\Resources\MenuAddOnGroups\Pages\ManageMenuAddOnGroups;
use App\Filament\Tenant\Resources\MenuAddOnGroups\Schemas\MenuAddOnGroupForm;
use App\Filament\Tenant\Resources\MenuAddOnGroups\Tables\MenuAddOnGroupsTable;
use App\Models\MenuAddOnGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The choices this tenant's items are customised with: a spice level, a bread, extras.
 *
 * A library rather than something inside each item, because one group is offered
 * on many items — edit "Spice level" once and every curry has it. Groups are made
 * and edited in modals on the one list, and linked to items either from here
 * (Attach to items) or from an item's own form.
 *
 * Scoping is Filament's: the panel has a tenant, so every query here is limited
 * to the tenant in the subdomain and new groups are stamped with it. Who may use
 * the page is MenuAddOnGroupPolicy's business: menu.view to look, menu.manage to
 * change anything.
 */
class MenuAddOnGroupResource extends Resource
{
    #[\Override]
    protected static ?string $model = MenuAddOnGroup::class;

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    /**
     * After Items, whose customisations these are.
     */
    #[\Override]
    protected static ?int $navigationSort = 25;

    #[\Override]
    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Labels are methods rather than static properties because a property is
     * evaluated when the class loads, before the request has chosen a language.
     */
    public static function getNavigationGroup(): ?string
    {
        return __('panel.navigation.menu');
    }

    public static function getModelLabel(): string
    {
        return __('panel.add_on_groups.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('panel.add_on_groups.plural');
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
        return MenuAddOnGroupForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MenuAddOnGroupsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMenuAddOnGroups::route('/'),
        ];
    }
}
