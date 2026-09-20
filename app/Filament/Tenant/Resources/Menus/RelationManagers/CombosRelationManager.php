<?php

namespace App\Filament\Tenant\Resources\Menus\RelationManagers;

use App\Enums\ItemAvailability;
use App\Filament\Schemas\PricingFields;
use App\Filament\Tables\Reordering;
use App\Filament\Tenant\Resources\Menus\Schemas\MenuComboForm;
use App\Models\Menu;
use App\Models\MenuCombo;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use LogicException;

/**
 * A menu's combos, in the modal its Combos row and button open on the menu page.
 *
 * A combo hangs off the menu rather than off a category (.ai/rules/menus.md), so
 * this is where one is made, priced, filled and dragged into order against the
 * other combos. Where the combos sit among the categories is dragged on the menu
 * page itself.
 */
class CombosRelationManager extends RelationManager
{
    #[\Override]
    protected static string $relationship = 'combos';

    #[\Override]
    protected static ?string $recordTitleAttribute = 'name';

    #[\Override]
    protected static function getModelLabel(): ?string
    {
        return (string) __('panel.combos.label');
    }

    public function form(Schema $schema): Schema
    {
        return MenuComboForm::configure($schema, $this->menu()->getKey());
    }

    public function table(Table $table): Table
    {
        $currency = PricingFields::currency();

        return $table
            // The modal around the table is already headed "Combos".
            ->heading(null)
            ->columns([
                TextColumn::make('name')
                    ->label(__('panel.shared.name'))
                    ->description(fn (MenuCombo $record): ?string => $record->description),

                TextColumn::make('combo_items_count')
                    ->label(__('panel.combos.contents'))
                    ->icon(Heroicon::OutlinedListBullet)
                    ->counts('comboItems'),

                // The struck-through price rides under the real one rather than
                // taking a column that would be empty for most combos.
                TextColumn::make('price')
                    ->label(__('panel.items.price'))
                    ->formatStateUsing(fn (MenuCombo $record): string => $record->formattedPrice($currency))
                    ->description(fn (MenuCombo $record): ?string => $record->formattedComparePrice($currency))
                    ->alignEnd(),

                TextColumn::make('availability')
                    ->label(__('panel.items.availability'))
                    ->badge()
                    ->formatStateUsing(fn (ItemAvailability $state): string => $state->label())
                    ->color(fn (ItemAvailability $state): string => $state->color()),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('panel.combos.create'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->slideOver()
                    ->mutateDataUsing(fn (array $data): array => [
                        ...PricingFields::store($data, $currency),
                        // At the end of the combos, where whoever added it looks for it.
                        'position' => ((int) $this->menu()->combos()->max('position')) + 1,
                    ])
                    ->successNotificationTitle(__('panel.arrangement.combo_created')),
            ])
            ->recordActions([
                EditAction::make()
                    ->iconButton()
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->tooltip(__('panel.arrangement.edit'))
                    ->slideOver()
                    ->mutateRecordDataUsing(fn (array $data, MenuCombo $record): array => MenuComboForm::fillTranslations(
                        PricingFields::fill($data, $currency),
                        $record,
                    ))
                    ->mutateDataUsing(fn (array $data): array => PricingFields::store($data, $currency)),

                DeleteAction::make()
                    ->iconButton()
                    ->icon(Heroicon::OutlinedTrash)
                    ->tooltip(__('panel.arrangement.delete'))
                    ->modalDescription(__('panel.combos.delete_warning')),
            ])
            ->reorderable('position')
            ->reorderRecordsTriggerAction(Reordering::trigger())
            ->defaultSort('position')
            ->paginated(false)
            ->emptyStateHeading(__('panel.combos.empty_heading'))
            ->emptyStateDescription(__('panel.combos.empty_description'))
            ->emptyStateIcon(Heroicon::OutlinedSparkles);
    }

    private function menu(): Menu
    {
        $menu = $this->getOwnerRecord();

        return $menu instanceof Menu ? $menu : throw new LogicException('The combos table requires a menu.');
    }
}
