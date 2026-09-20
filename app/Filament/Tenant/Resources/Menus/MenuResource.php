<?php

namespace App\Filament\Tenant\Resources\Menus;

use App\Filament\Schemas\TranslatedFields;
use App\Filament\Tenant\Resources\Menus\Pages\ArrangeMenu;
use App\Filament\Tenant\Resources\Menus\Pages\EditMenu;
use App\Filament\Tenant\Resources\Menus\Pages\ListMenus;
use App\Filament\Tenant\Resources\Menus\Schemas\MenuForm;
use App\Filament\Tenant\Resources\Menus\Tables\MenusTable;
use App\Models\Menu;
use BackedEnum;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The menus this tenant serves: Lunch, Dinner, Drinks.
 *
 * The top of the hierarchy the panel edits. One menu opens on everything it
 * holds — its categories, their subdivisions, the items in each, its featured
 * items and its combos — all in one list, added to, edited and dragged into
 * order there. The Items page still lists every item across every menu, for
 * finding one without knowing where it is filed.
 *
 * A tenant that serves one card all day simply keeps one menu.
 *
 * Scoping is Filament's: the panel has a tenant, so every query here is limited
 * to the tenant in the subdomain and new rows are stamped with it. Who may
 * use the page is MenuPolicy's business, through menu.view and menu.manage.
 */
class MenuResource extends Resource
{
    #[\Override]
    protected static ?string $model = Menu::class;

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    #[\Override]
    protected static ?string $recordTitleAttribute = 'name';

    /**
     * First in the group, because it is the level everything else hangs off.
     */
    #[\Override]
    protected static ?int $navigationSort = 5;

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
        return __('panel.menus.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('panel.menus.plural');
    }

    /**
     * The top bar's search looks in the language the panel is showing.
     *
     * Left to the title attribute, it matched `name` as raw JSON text — Tamil
     * never matched and "en" matched every menu.
     *
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return TranslatedFields::searchableAttributes('name');
    }

    public static function form(Schema $schema): Schema
    {
        return MenuForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MenusTable::configure($table);
    }

    /**
     * A menu is two tabs across the top of one record.
     *
     * Arrangement comes first because it is what a menu mostly *is*: its
     * rails, categories and sub-categories in the order a guest reads them,
     * each opening a table of what is inside it. Edit is the menu itself — its
     * name, its hours and whether guests see it.
     *
     * Featured items and combos were tabs of their own and are neither any
     * more: each is a row of the arrangement that opens its own table.
     *
     * @return array<int, NavigationItem>
     */
    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([
            ArrangeMenu::class,
            EditMenu::class,
        ]);
    }

    public static function getSubNavigationPosition(): SubNavigationPosition
    {
        return SubNavigationPosition::Top;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMenus::route('/'),
            'arrange' => ArrangeMenu::route('/{record}/arrange'),
            'edit' => EditMenu::route('/{record}/edit'),
        ];
    }
}
