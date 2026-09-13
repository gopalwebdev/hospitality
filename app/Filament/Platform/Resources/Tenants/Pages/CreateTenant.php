<?php

namespace App\Filament\Platform\Resources\Restaurants\Pages;

use App\Enums\Role;
use App\Filament\Platform\Resources\Restaurants\RestaurantResource;
use App\Filament\Platform\Resources\Users\UserResource;
use App\Models\Restaurant;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

/**
 * Onboarding a restaurant, and then the one account that runs it.
 *
 * A restaurant with nobody on its roster cannot be opened by anyone, so
 * creating one leads straight into creating its administrator rather than
 * back to the list. The account itself is made on the Users page, which holds
 * the one-time code confirmation that authorises opening an account at all —
 * minting an admin from here would go around that.
 */
class CreateRestaurant extends CreateRecord
{
    protected static string $resource = RestaurantResource::class;

    /**
     * Creating another restaurant before this one has an admin is how a
     * restaurant nobody can open gets left behind.
     */
    protected static bool $canCreateAnother = false;

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Restaurant created';
    }

    /**
     * Hand the new restaurant to the account form, already chosen.
     */
    protected function getRedirectUrl(): string
    {
        $restaurant = $this->getRecord();

        if (! $restaurant instanceof Restaurant) {
            return $this->getResource()::getUrl('index');
        }

        Notification::make()
            ->title('Now add its administrator')
            ->body(sprintf('%s has no accounts yet. This form opens with the restaurant and the %s role already chosen.', $restaurant->name, Role::Admin->value))
            ->info()
            ->send();

        return UserResource::getUrl('create', [
            'tenant_id' => $restaurant->getKey(),
            'role' => Role::Admin->value,
        ]);
    }
}
