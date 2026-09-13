<?php

namespace App\Filament\Tenant\Resources\Users\Tables;

use App\Actions\Tenants\RemoveUserFromTenant;
use App\Filament\Tenant\CurrentTenant;
use App\Models\Tenant;
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
                // other tenants, so this only takes them off this roster.
                Action::make('removeFromTenant')
                    ->label('Remove')
                    ->iconButton()
                    ->icon(Heroicon::OutlinedUserMinus)
                    ->color('danger')
                    ->authorize('removeFromTenant')
                    ->requiresConfirmation()
                    ->modalHeading(fn (): string => 'Remove from this '.CurrentTenant::noun())
                    ->modalDescription(fn (): string => 'Their account stays, and they lose access to this '.CurrentTenant::noun().'.')
                    ->action(function (User $record): void {
                        $tenant = Filament::getTenant();

                        throw_unless($tenant instanceof Tenant, LogicException::class, 'Removing a user requires a tenant.');

                        app(RemoveUserFromTenant::class)($tenant, $record);

                        Notification::make()
                            ->title('Removed from this '.$tenant->type->noun())
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('name');
    }
}
