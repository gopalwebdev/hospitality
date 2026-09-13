<?php

namespace App\Filament\Restaurant\Resources\Users\Tables;

use App\Actions\Restaurants\RemoveUserFromRestaurant;
use App\Models\Restaurant;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use LogicException;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->icon(Heroicon::OutlinedUser)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Email address')
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->searchable()
                    ->copyable(),
                TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->placeholder('None'),
                TextColumn::make('created_at')
                    ->label('Account created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make()
                    ->iconButton()
                    ->icon(Heroicon::OutlinedPencilSquare),

                // Not a delete: the account is platform-wide and may staff
                // other restaurants, so this only takes them off this roster.
                Action::make('removeFromRestaurant')
                    ->label('Remove')
                    ->iconButton()
                    ->icon(Heroicon::OutlinedUserMinus)
                    ->color('danger')
                    ->authorize('removeFromRestaurant')
                    ->requiresConfirmation()
                    ->modalHeading('Remove from this restaurant')
                    ->modalDescription('Their account stays, and they lose access to this restaurant.')
                    ->action(function (User $record): void {
                        $restaurant = Filament::getTenant();

                        throw_unless($restaurant instanceof Restaurant, LogicException::class, 'Removing a user requires a restaurant tenant.');

                        app(RemoveUserFromRestaurant::class)($restaurant, $record);

                        Notification::make()
                            ->title('Removed from this restaurant')
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('name');
    }
}
