<?php

namespace App\Filament\Platform\Resources\Restaurants\Tables;

use App\Models\Restaurant;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class RestaurantsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label('Subdomain')
                    ->searchable(),
                TextColumn::make('phone')
                    ->label('Mobile')
                    ->state(fn (Restaurant $record): string => $record->dialablePhone())
                    ->searchable(),
                TextColumn::make('email')
                    ->searchable()
                    ->placeholder('None')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('secondary_phone')
                    ->label('Secondary mobile')
                    ->state(fn (Restaurant $record): ?string => $record->dialableSecondaryPhone())
                    ->placeholder('None')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('address')
                    ->limit(40)
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('pincode')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')
                    ->label('Open')
                    ->boolean(),
                TextColumn::make('users_count')
                    ->label('Staff')
                    ->counts('users'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Open for business'),
            ])
            ->recordActions([
                // The restaurant panel's own tenant menu is off (see
                // .ai/rules/filament.md), so a restaurant's name is the only
                // thing shown there — this is how a super admin supporting one
                // restaurant gets to its panel.
                Action::make('openPanel')
                    ->label('Open dashboard')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->iconButton()
                    ->url(fn (Restaurant $record): string => $record->signInUrl())
                    ->openUrlInNewTab(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }
}
