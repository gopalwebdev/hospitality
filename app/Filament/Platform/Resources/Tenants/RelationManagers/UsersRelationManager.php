<?php

namespace App\Filament\Platform\Resources\Restaurants\RelationManagers;

use App\Filament\Platform\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Who staffs this restaurant, shown under its own record.
 *
 * Read-only on purpose. An account is platform-wide, so creating, moving and
 * deleting one all belong to the Users resource, which is the only place that
 * knows about the product team as well; this is the roster answering "who is
 * at this restaurant", with a way through to each account. Roster membership
 * itself is a restaurant's own business and is changed from its panel.
 */
class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'Users';

    protected static string|\BackedEnum|null $icon = Heroicon::OutlinedUsers;

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->heading('Users')
            ->description('Every account on this restaurant’s roster.')
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

                // Belonging here and being rostered here are two facts, and
                // they can disagree: someone who staffs two restaurants belongs
                // to one of them. Saying so beats quietly showing them twice.
                IconColumn::make('tenant_id')
                    ->label('Belongs here')
                    ->boolean()
                    ->state(fn (User $record): bool => $record->tenant_id === $this->getOwnerRecord()->getKey())
                    ->tooltip('Off means they staff this restaurant but belong to another.'),

                TextColumn::make('created_at')
                    ->label('Account created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('roles')
                    ->label('Role')
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload(),
            ])
            ->headerActions([
                // Accounts are created in the Users resource, which holds the
                // one-time code confirmation that authorises it. This carries
                // the restaurant through so the form opens already filled in.
                CreateAction::make()
                    ->label('Add an account')
                    ->icon(Heroicon::OutlinedUserPlus)
                    ->url(fn (): string => UserResource::getUrl('create', [
                        'tenant_id' => $this->getOwnerRecord()->getKey(),
                    ])),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Edit')
                    ->iconButton()
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->url(fn (User $record): string => UserResource::getUrl('edit', ['record' => $record])),
            ])
            ->emptyStateHeading('Nobody works here yet')
            ->emptyStateDescription('A restaurant needs an admin before anyone can sign in to run it.')
            ->emptyStateIcon(Heroicon::OutlinedUsers)
            ->defaultSort('name');
    }
}
