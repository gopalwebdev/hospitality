<?php

namespace App\Filament\Restaurant\Resources\Users\Pages;

use App\Actions\Restaurants\AddUserToRestaurant;
use App\Filament\Restaurant\Resources\Users\UserResource;
use App\Models\Restaurant;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * Join this restaurant, on an existing account where there is one.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $restaurant = Filament::getTenant();

        throw_unless($restaurant instanceof Restaurant, LogicException::class, 'Adding a user requires a restaurant tenant.');

        return app(AddUserToRestaurant::class)(
            $restaurant,
            (string) $data['name'],
            (string) $data['email'],
            array_values((array) ($data['roles'] ?? [])),
        );
    }
}
