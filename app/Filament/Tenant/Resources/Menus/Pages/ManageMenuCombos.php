<?php

namespace App\Filament\Restaurant\Resources\Menus\Pages;

use App\Enums\ItemAvailability;
use App\Filament\Restaurant\Resources\Menus\MenuResource;
use App\Filament\Restaurant\Resources\Menus\Schemas\MenuComboForm;
use App\Filament\Schemas\PricingFields;
use App\Filament\Schemas\TranslatedFields;
use App\Filament\Tables\Reordering;
use App\Models\Menu;
use App\Models\MenuCombo;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * The bundles this menu offers, in the order a guest reads them.
 *
 * A combo hangs off the menu rather than off a category — it is something a
 * menu leads with, not something in a section — so this is where one is made,
 * priced and dragged into order against the other combos. Where the combos rail
 * sits among the menu's categories is dragged on the arrangement page instead.
 *
 * Unlike the featured rail, records really are created and deleted here: a
 * combo is a thing in its own right, not a flag on a dish.
 */
class ManageMenuCombos extends ManageRelatedRecords
{
    protected static string $resource = MenuResource::class;

    protected static string $relationship = 'combos';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    public function getTitle(): string
    {
        return (string) __('panel.combos.plural');
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('panel.combos.plural');
    }

    public function form(Schema $schema): Schema
    {
        return MenuComboForm::configure($schema, $this->menu()->getKey());
    }

    public function table(Table $table): Table
    {
        $currency = PricingFields::currency();

        return $table
            ->recordTitleAttribute('name')
            ->heading(__('panel.combos.plural'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('panel.shared.name'))
                    ->icon(Heroicon::OutlinedSparkles)
                    ->searchable(query: fn (Builder $query, string $search): Builder => TranslatedFields::search($query, 'name', $search))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => TranslatedFields::sort($query, 'name', $direction))
                    ->description(fn (MenuCombo $record): ?string => $record->description),

                TextColumn::make('combo_items_count')
                    ->label(__('panel.combos.contents'))
                    ->icon(Heroicon::OutlinedListBullet)
                    ->counts('comboItems')
                    ->sortable(),

                // Formatted here rather than in the browser: a panel is server
                // rendered, and the currency is resolved once for the page
                // rather than per row. See .ai/rules/models.md.
                TextColumn::make('price_minor_units')
                    ->label(__('panel.items.price'))
                    ->formatStateUsing(fn (MenuCombo $record): string => $record->formattedPrice($currency))
                    // The struck-through price rides under the real one rather
                    // than taking a column of its own, which would be empty for
                    // every combo that is not on offer.
                    ->description(fn (MenuCombo $record): ?string => $record->formattedComparePrice($currency))
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('availability')
                    ->label(__('panel.items.availability'))
                    ->badge()
                    ->formatStateUsing(fn (ItemAvailability $state): string => $state->label())
                    ->color(fn (ItemAvailability $state): string => $state->color())
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('panel.combos.create'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->mutateDataUsing(fn (array $data): array => PricingFields::store($data, $currency)),
            ])
            ->recordActions([
                EditAction::make()
                    ->iconButton()
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->mutateRecordDataUsing(fn (array $data, MenuCombo $record): array => MenuComboForm::fillTranslations(
                        PricingFields::fill($data, $currency),
                        $record,
                    ))
                    ->mutateDataUsing(fn (array $data): array => PricingFields::store($data, $currency)),

                DeleteAction::make()
                    ->iconButton()
                    ->icon(Heroicon::OutlinedTrash)
                    ->modalDescription(__('panel.combos.delete_warning')),
            ])
            ->reorderable('position')
            ->reorderRecordsTriggerAction(Reordering::trigger())
            ->defaultSort('position')
            ->emptyStateHeading(__('panel.combos.empty_heading'))
            ->emptyStateDescription(__('panel.combos.empty_description'))
            ->emptyStateIcon(Heroicon::OutlinedSparkles);
    }

    private function menu(): Menu
    {
        $menu = $this->getOwnerRecord();

        return $menu instanceof Menu ? $menu : throw new LogicException('The combos page requires a menu.');
    }
}
