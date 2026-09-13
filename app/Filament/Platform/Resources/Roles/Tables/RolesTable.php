<?php

namespace App\Filament\Platform\Resources\Roles\Tables;

use App\Enums\Role as RoleEnum;
use App\Filament\Platform\Resources\Roles\RoleResource;
use App\Models\Role;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RolesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->icon(Heroicon::OutlinedIdentification)
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_built_in')
                    ->label('Built in')
                    ->state(fn (Role $record): bool => $record->isBuiltIn())
                    ->boolean()
                    ->tooltip('Built-in roles are declared in code and seeded, so they are read-only.'),
                TextColumn::make('permissions_count')
                    ->label('Permissions')
                    ->icon(Heroicon::OutlinedKey)
                    ->counts('permissions')
                    ->sortable(),
                TextColumn::make('users_count')
                    ->label('Users')
                    ->icon(Heroicon::OutlinedUsers)
                    ->counts('users')
                    ->sortable(),

                // A row whose delete button is missing says why here, rather
                // than leaving an admin to guess at the gap.
                IconColumn::make('is_deletable')
                    ->label('Deletable')
                    ->state(fn (Role $record): bool => $record->undeletableReason() === null)
                    ->boolean()
                    ->tooltip(fn (Role $record): string => $record->undeletableReason() ?? 'Nothing depends on this role.'),
            ])
            ->filters([
                // Built-in is not a column, so the filter asks the same
                // question Role::isBuiltIn() does: is the name an enum case?
                TernaryFilter::make('is_built_in')
                    ->label('Built in')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereIn('name', RoleEnum::values()),
                        false: fn (Builder $query): Builder => $query->whereNotIn('name', RoleEnum::values()),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->recordActions([
                ViewAction::make()
                    ->iconButton()
                    ->icon(Heroicon::OutlinedEye),

                // A built-in or in-use role is read-only, and the resource is
                // where that is decided. Without an explicit check the buttons
                // would render for an admin and then land on a 403,
                // because Filament authorizes a record action against the
                // policy, which Gate::before answers first.
                EditAction::make()
                    ->iconButton()
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (Role $record): bool => RoleResource::canEdit($record)),
                DeleteAction::make()
                    ->iconButton()
                    ->icon(Heroicon::OutlinedTrash)
                    ->visible(fn (Role $record): bool => RoleResource::canDelete($record)),
            ])
            // No bulk delete: the only roles that may be deleted are the custom
            // ones nothing depends on, and a bulk action would have to re-check
            // that per record.
            ->defaultSort('name');
    }
}
