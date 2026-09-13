<?php

namespace App\Filament\Restaurant\Resources\HomeRows\Tables;

use App\Enums\HomeRowLayout;
use App\Filament\Restaurant\Resources\HomeRows\Schemas\HomeRowForm;
use App\Filament\Schemas\TranslatedFields;
use App\Filament\Tables\Reordering;
use App\Models\HomeRow;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class HomeRowsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label(__('panel.rows.title_field'))
                    ->icon(Heroicon::OutlinedTag)
                    ->placeholder(__('panel.rows.untitled'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => TranslatedFields::search($query, 'title', $search))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => TranslatedFields::sort($query, 'title', $direction)),

                TextColumn::make('layout')
                    ->label(__('panel.rows.layout_column'))
                    ->badge()
                    ->color('gray')
                    ->icon(fn (HomeRowLayout $state): Heroicon => match ($state) {
                        HomeRowLayout::Banner => Heroicon::OutlinedRectangleGroup,
                        HomeRowLayout::Carousel => Heroicon::OutlinedPhoto,
                        HomeRowLayout::Links => Heroicon::OutlinedLink,
                    })
                    ->formatStateUsing(fn (HomeRowLayout $state): string => $state->label()),

                TextColumn::make('tiles_count')
                    ->label(__('panel.rows.tiles_count'))
                    ->icon(Heroicon::OutlinedSquares2x2)
                    ->counts('tiles')
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label(__('panel.shared.showing'))
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('layout')
                    ->label(__('panel.rows.layout_column'))
                    ->options(HomeRowLayout::options()),

                TernaryFilter::make('is_active')->label(__('panel.shared.showing')),
            ])
            ->recordActions([

                EditAction::make()
                    ->iconButton()
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->mutateRecordDataUsing(fn (array $data, HomeRow $record): array => HomeRowForm::fillTranslations($data, $record)),

                DeleteAction::make()
                    ->iconButton()
                    ->icon(Heroicon::OutlinedTrash)
                    ->modalDescription(__('panel.rows.delete_warning')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->reorderable('position')
            ->reorderRecordsTriggerAction(Reordering::trigger())
            ->defaultSort('position')
            ->emptyStateHeading(__('panel.rows.empty_heading'))
            ->emptyStateDescription(__('panel.rows.empty_description'))
            ->emptyStateIcon(Heroicon::OutlinedViewColumns);
    }
}
