<?php

namespace App\Filament\Tenant\Resources\MenuItems;

use App\Filament\Schemas\TranslatedFields;
use App\Filament\Tenant\Resources\MenuItems\Pages\ListMenuItems;
use App\Filament\Tenant\Resources\MenuItems\Schemas\MenuItemForm;
use App\Filament\Tenant\Resources\MenuItems\Tables\MenuItemsTable;
use App\Models\MenuItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * What this tenant sells.
 *
 * Scoping is Filament's: the panel has a tenant, so every query here is limited
 * to the tenant in the subdomain and new rows are stamped with it.
 * MenuItemObserver is the second half of that — even a tampered form cannot
 * file an item under another tenant's section.
 *
 * Who may use the page is MenuItemPolicy's business: menu.view to look,
 * menu.manage to change anything.
 */
class MenuItemResource extends Resource
{
    protected static ?string $model = MenuItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?int $navigationSort = 20;

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
        return __('panel.items.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('panel.items.plural');
    }

    /**
     * The top bar's search looks in the language the panel is showing.
     *
     * Left to the title attribute, it matched `name` as raw JSON text — Tamil
     * never matched and "en" matched every item.
     *
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return TranslatedFields::searchableAttributes('name');
    }

    public static function form(Schema $schema): Schema
    {
        return MenuItemForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MenuItemsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMenuItems::route('/'),
        ];
    }
}
