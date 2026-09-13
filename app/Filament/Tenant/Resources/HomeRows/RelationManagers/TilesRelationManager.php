<?php

namespace App\Filament\Restaurant\Resources\HomeRows\RelationManagers;

use App\Enums\HomeTileAction;
use App\Filament\Restaurant\Resources\HomeRows\Schemas\HomeTileForm;
use App\Filament\Schemas\TranslatedFields;
use App\Filament\Tables\Reordering;
use App\Models\HomeTile;
use App\Models\Menu;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The tiles inside one row of the home screen.
 *
 * Tiles live here rather than on a page of their own because a tile only means
 * anything inside a row — the row decides how it is drawn, and the order they
 * are dragged into here is the order a guest reads them along the band.
 */
class TilesRelationManager extends RelationManager
{
    protected static string $relationship = 'tiles';

    protected static string|\BackedEnum|null $icon = Heroicon::OutlinedSquares2x2;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('panel.rows.manage_tiles');
    }

    public function form(Schema $schema): Schema
    {
        return HomeTileForm::configure($schema);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->heading(__('panel.rows.manage_tiles'))
            ->description(__('panel.rows.manage_tiles_help'))
            ->columns([
                ImageColumn::make('image_path')
                    ->label(__('panel.tiles.picture_column'))
                    ->disk('local')
                    ->visibility('private')
                    ->imageHeight(40)
                    ->defaultImageUrl(null)
                    ->placeholder(__('panel.tiles.no_picture')),

                TextColumn::make('label')
                    ->label(__('panel.tiles.label_field'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => TranslatedFields::search($query, 'label', $search))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => TranslatedFields::sort($query, 'label', $direction)),

                TextColumn::make('action')
                    ->label(__('panel.tiles.on_tap'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (HomeTileAction $state): string => $state->label()),

                TextColumn::make('destination')
                    ->label(__('panel.tiles.goes_to'))
                    ->state(fn (HomeTile $record): string => $this->destinationOf($record))
                    ->icon(fn (HomeTile $record): Heroicon => match ($record->action) {
                        HomeTileAction::Menu => Heroicon::OutlinedBookOpen,
                        HomeTileAction::Pdf => Heroicon::OutlinedDocumentText,
                        HomeTileAction::Link => Heroicon::OutlinedLink,
                    }),

                IconColumn::make('is_active')
                    ->label(__('panel.shared.showing'))
                    ->boolean()
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('panel.tiles.create'))
                    ->icon(Heroicon::OutlinedPlus),
            ])
            ->recordActions([
                EditAction::make()
                    ->iconButton()
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->mutateRecordDataUsing(fn (array $data, HomeTile $record): array => HomeTileForm::fillTranslations($data, $record)),

                DeleteAction::make()
                    ->iconButton()
                    ->icon(Heroicon::OutlinedTrash)
                    ->modalDescription(__('panel.tiles.delete_warning')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->reorderable('position')
            ->reorderRecordsTriggerAction(Reordering::trigger())
            ->defaultSort('position')
            ->emptyStateHeading(__('panel.tiles.empty_heading'))
            ->emptyStateDescription(__('panel.tiles.empty_description'))
            ->emptyStateIcon(Heroicon::OutlinedSquares2x2)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('menu'));
    }

    /**
     * Where a tile goes, in one phrase whichever kind it is.
     *
     * One question to a reader, three columns in the schema. menu_id is
     * nullable because a PDF or link tile has none, so the menu is checked
     * rather than assumed.
     */
    private function destinationOf(HomeTile $tile): string
    {
        if ($tile->action === HomeTileAction::Pdf) {
            return __('panel.tiles.an_uploaded_pdf');
        }

        if ($tile->action === HomeTileAction::Link) {
            return $tile->url ?? __('panel.tiles.a_link');
        }

        $menu = $tile->menu;

        return $menu instanceof Menu ? $menu->name : __('panel.tiles.no_menu_chosen');
    }
}
