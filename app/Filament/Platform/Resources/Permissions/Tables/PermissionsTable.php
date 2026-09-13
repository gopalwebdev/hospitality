<?php

namespace App\Filament\Platform\Resources\Permissions\Tables;

use App\Enums\PermissionGroup;
use App\Filament\Platform\Resources\Permissions\PermissionResource;
use App\Models\Permission;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PermissionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->icon(Heroicon::OutlinedKey)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('label')
                    ->label('Reads as')
                    ->state(fn (Permission $record): string => $record->label()),

                // A dozen `subject.ability` strings say nothing on their own,
                // so every row carries the category it belongs to.
                TextColumn::make('category')
                    ->label('Category')
                    ->state(fn (Permission $record): string => $record->group()->label())
                    ->icon(fn (Permission $record): Heroicon => $record->group()->icon())
                    ->badge()
                    ->color(fn (Permission $record): string => $record->group()->color()),
                IconColumn::make('is_built_in')
                    ->label('Built in')
                    ->state(fn (Permission $record): bool => $record->isBuiltIn())
                    ->boolean()
                    ->tooltip('Built-in permissions are checked by name from code, so they are read-only.'),
                TextColumn::make('roles_count')
                    ->label('Roles')
                    ->icon(Heroicon::OutlinedIdentification)
                    ->counts('roles')
                    ->sortable(),

                // A row whose delete button is missing says why here, rather
                // than leaving an admin to guess at the gap.
                IconColumn::make('is_deletable')
                    ->label('Deletable')
                    ->state(fn (Permission $record): bool => $record->undeletableReason() === null)
                    ->boolean()
                    ->tooltip(fn (Permission $record): string => $record->undeletableReason() ?? 'No role holds this permission.'),
            ])
            ->groups([
                // Category is derived from the name rather than stored, so the
                // group states how to key, title and order itself; ordering by
                // name keeps each category's rows together, because the name's
                // subject half is what puts a permission in one.
                Group::make('category')
                    ->label('Category')
                    ->getKeyFromRecordUsing(fn (Permission $record): string => $record->group()->value)
                    ->getTitleFromRecordUsing(fn (Permission $record): string => $record->group()->label())
                    ->getDescriptionFromRecordUsing(fn (Permission $record): string => $record->group()->description())
                    ->orderQueryUsing(fn (Builder $query): Builder => $query->orderBy('name')),
            ])
            ->defaultGroup('category')
            ->filters([
                SelectFilter::make('category')
                    ->label('Category')
                    ->options(fn (): array => collect(PermissionGroup::ordered())
                        ->mapWithKeys(fn (PermissionGroup $group): array => [$group->value => $group->label()])
                        ->all())
                    ->query(function (Builder $query, array $data): Builder {
                        $group = PermissionGroup::tryFrom((string) ($data['value'] ?? ''));

                        if (! $group instanceof PermissionGroup) {
                            return $query;
                        }

                        // Category lives in the name, not in a column, so the
                        // filter matches on the subject prefix. Other is
                        // whatever no group claims, which is every prefix
                        // ruled out rather than any ruled in.
                        $subjects = $group->subjects();

                        if ($subjects === []) {
                            return $query->where(function (Builder $names): void {
                                foreach (PermissionGroup::everySubject() as $subject) {
                                    $names->where('name', 'not like', $subject.'.%');
                                }
                            });
                        }

                        return $query->where(function (Builder $names) use ($subjects): void {
                            foreach ($subjects as $subject) {
                                $names->orWhere('name', 'like', $subject.'.%');
                            }
                        });
                    }),
            ])
            ->recordActions([
                ViewAction::make()
                    ->iconButton()
                    ->icon(Heroicon::OutlinedEye),

                // As with roles: a built-in or in-use permission is read-only,
                // and offering the button would lead an admin to a 403.
                EditAction::make()
                    ->iconButton()
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (Permission $record): bool => PermissionResource::canEdit($record)),
                DeleteAction::make()
                    ->iconButton()
                    ->icon(Heroicon::OutlinedTrash)
                    ->visible(fn (Permission $record): bool => PermissionResource::canDelete($record)),
            ])
            // No bulk delete: only custom permissions no role holds may be
            // deleted, and that is a per-record question.
            ->defaultSort('name');
    }
}
