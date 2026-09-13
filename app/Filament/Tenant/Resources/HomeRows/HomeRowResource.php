<?php

namespace App\Filament\Restaurant\Resources\HomeRows;

use App\Filament\Restaurant\Resources\HomeRows\Pages\EditHomeRow;
use App\Filament\Restaurant\Resources\HomeRows\Pages\ListHomeRows;
use App\Filament\Restaurant\Resources\HomeRows\RelationManagers\TilesRelationManager;
use App\Filament\Restaurant\Resources\HomeRows\Schemas\HomeRowForm;
use App\Filament\Restaurant\Resources\HomeRows\Tables\HomeRowsTable;
use App\Filament\Schemas\TranslatedFields;
use App\Models\HomeRow;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The home screen a guest lands on after scanning a table's QR code.
 *
 * A home screen is rows, and a row is tiles. The rows are dragged into order
 * here; the tiles inside one are managed on that row's own page, because a
 * tile only means anything inside the row that decides how it is drawn.
 *
 * Its own navigation group rather than sitting under Menu: this is the shop
 * window, and a tile may open a PDF or leave for Instagram, neither of which
 * has anything to do with food.
 *
 * Scoping is Filament's: the panel has a tenant, so every query here is limited
 * to the restaurant in the subdomain. Who may use the page is HomeRowPolicy's
 * business, through storefront.view and storefront.manage.
 */
class HomeRowResource extends Resource
{
    protected static ?string $model = HomeRow::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $tenantOwnershipRelationshipName = 'restaurant';

    /**
     * Labels are methods rather than static properties because a property is
     * evaluated when the class loads, before the request has chosen a language.
     */
    public static function getNavigationGroup(): ?string
    {
        return __('panel.navigation.storefront');
    }

    public static function getModelLabel(): string
    {
        return __('panel.rows.label');
    }

    /**
     * The top bar's search looks in the language the panel is showing.
     *
     * Left to the title attribute, it matched `title` as raw JSON text — Tamil
     * never matched and "en" matched every row with a title.
     *
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return TranslatedFields::searchableAttributes('title');
    }

    public static function getPluralModelLabel(): string
    {
        return __('panel.rows.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return HomeRowForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return HomeRowsTable::configure($table);
    }

    /**
     * A row's tiles hang under its own page, below the form that shapes them.
     */
    public static function getRelations(): array
    {
        return [
            TilesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHomeRows::route('/'),
            'edit' => EditHomeRow::route('/{record}/edit'),
        ];
    }
}
