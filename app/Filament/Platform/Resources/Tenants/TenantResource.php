<?php

namespace App\Filament\Platform\Resources\Restaurants;

use App\Filament\Platform\Resources\Restaurants\Pages\CreateRestaurant;
use App\Filament\Platform\Resources\Restaurants\Pages\EditRestaurant;
use App\Filament\Platform\Resources\Restaurants\Pages\ListRestaurants;
use App\Filament\Platform\Resources\Restaurants\RelationManagers\UsersRelationManager;
use App\Filament\Platform\Resources\Restaurants\Schemas\RestaurantForm;
use App\Filament\Platform\Resources\Restaurants\Tables\RestaurantsTable;
use App\Models\Restaurant;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class RestaurantResource extends Resource
{
    protected static ?string $model = Restaurant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return RestaurantForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RestaurantsTable::configure($table);
    }

    /**
     * The roster sits under the restaurant's own record, below its form.
     */
    public static function getRelations(): array
    {
        return [
            UsersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRestaurants::route('/'),
            'create' => CreateRestaurant::route('/create'),
            'edit' => EditRestaurant::route('/{record}/edit'),
        ];
    }
}
