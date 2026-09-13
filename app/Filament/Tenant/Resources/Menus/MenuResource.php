<?php

namespace App\Filament\Tenant\Resources\Menus;

use App\Filament\Schemas\TranslatedFields;
use App\Filament\Tenant\Resources\Menus\Pages\ArrangeMenu;
use App\Filament\Tenant\Resources\Menus\Pages\EditMenu;
use App\Filament\Tenant\Resources\Menus\Pages\ListMenus;
use App\Filament\Tenant\Resources\Menus\Pages\ManageMenuCombos;
use App\Filament\Tenant\Resources\Menus\Pages\ManageMenuFeaturedItems;
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
 * The top of the hierarchy the panel edits. One menu opens on its arrangement:
 * its categories, their subdivisions, the dishes in each and the two rails it
 * leads with, all in one list and all dragged into order there. Dishes are
 * still their own page — there are far more of them, and they are the thing a
 * tenant edits daily.
 *
 * A tenant that serves one card all day simply keeps one menu.
 *
 * Scoping is Filament's: the panel has a tenant, so every query here is limited
 * to the tenant in the subdomain and new rows are stamped with it. Who may
 * use the page is MenuPolicy's business, through menu.view and menu.manage.
 */
class MenuResource extends Resource
{
    protected static ?string $model = Menu::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * First in the group, because it is the level everything else hangs off.
     */
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
     * A menu is four tabs across the top of one record, not four tables down
     * one page.
     *
     * Arrangement comes first because it is what a menu mostly *is*: every
     * category, every subdivision and every dish in the order a guest reads
     * them, with the featured and combo rails sitting among them. The three
     * that follow are the details behind it — what the menu is called and when
     * it is served, which dishes it leads with, and the combos it sells.
     *
     * Categories and sub-categories were two of those tabs and are neither any
     * more: both are rows of the arrangement, where the dishes under them are
     * finally visible in the same list.
     *
     * @return array<int, NavigationItem>
     */
    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([
            ArrangeMenu::class,
            EditMenu::class,
            ManageMenuFeaturedItems::class,
            ManageMenuCombos::class,
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
            'featured' => ManageMenuFeaturedItems::route('/{record}/featured'),
            'combos' => ManageMenuCombos::route('/{record}/combos'),
        ];
    }
}
