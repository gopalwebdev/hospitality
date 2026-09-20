<?php

namespace App\Filament\Tenant\Resources\Menus\RelationManagers;

use App\Enums\Currency;
use App\Enums\ItemAvailability;
use App\Enums\MenuItemKind;
use App\Filament\Schemas\PricingFields;
use App\Filament\Tables\Reordering;
use App\Filament\Tables\StockActions;
use App\Filament\Tenant\Resources\MenuItems\Schemas\MenuItemForm;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use LogicException;

/**
 * The items of one category or sub-category, in the modal its row opens on the menu page.
 *
 * The menu page is an outline — the featured items, the combos, the categories
 * and their sub-categories — and lists nothing that is inside a category. This
 * table is where a category's items are put in order, added, edited and deleted.
 *
 * A relation manager that no resource page lists: it is exactly Filament's table
 * of one record's related rows, with create, edit, delete and drag already bound
 * to the model and its policy. MenuArrangementTable::contentsOf() renders it in
 * the modal. It is deliberately not in MenuResource::getRelations(), which would
 * draw it under the Edit tab as well.
 */
class CategoryItemsRelationManager extends RelationManager
{
    #[\Override]
    protected static string $relationship = 'menuItems';

    #[\Override]
    protected static ?string $recordTitleAttribute = 'name';

    #[\Override]
    protected static function getModelLabel(): ?string
    {
        return (string) __('panel.items.label');
    }

    public function form(Schema $schema): Schema
    {
        return MenuItemForm::configure($schema, categoryId: $this->category()->getKey());
    }

    public function table(Table $table): Table
    {
        return $table
            // The modal around the table is already headed with the category's name.
            ->heading(null)
            ->columns([
                ...self::columns(PricingFields::currency()),

                TextColumn::make('is_featured')
                    ->label(__('panel.items.featured_column'))
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => (string) ($state ? __('panel.shared.yes') : __('panel.shared.no')))
                    ->icon(fn (bool $state): Heroicon => $state ? Heroicon::OutlinedStar : Heroicon::OutlinedMinusSmall)
                    ->color(fn (bool $state): string => $state ? 'warning' : 'gray'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('panel.items.create'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->slideOver()
                    // The item form is laid out in columns for the whole width.
                    ->modalWidth(Width::Full)
                    // Filed where the form says, which is this category unless it
                    // was changed. Created through the relationship instead, a
                    // different choice in that select would be quietly overruled.
                    ->using(fn (array $data): MenuItem => MenuItem::query()->create([
                        ...MenuItemForm::storeNew($data),
                        // At the bottom of its category, where whoever added it looks for it.
                        'position' => ((int) MenuItem::query()->where('menu_category_id', $data['menu_category_id'])->max('position')) + 1,
                    ]))
                    ->successNotificationTitle(__('panel.arrangement.item_created')),
            ])
            ->recordActions([
                StockActions::adjust(),
                StockActions::history(),
                self::editAction(),

                DeleteAction::make()
                    ->iconButton()
                    ->icon(Heroicon::OutlinedTrash)
                    ->tooltip(__('panel.arrangement.delete'))
                    ->modalDescription(__('panel.items.delete_warning')),
            ])
            ->reorderable('position')
            ->reorderRecordsTriggerAction(Reordering::trigger())
            ->defaultSort('position')
            // One category's worth of items, and a drag has to be able to carry
            // the last of them to the top.
            ->paginated(false)
            ->emptyStateHeading(__('panel.items.category_empty_heading'))
            ->emptyStateDescription(__('panel.items.category_empty_description'))
            ->emptyStateIcon(Heroicon::OutlinedListBullet);
    }

    /**
     * What an item is, what it costs and whether it can be ordered — the featured items' table shows the same.
     *
     * @return list<TextColumn>
     */
    public static function columns(Currency $currency): array
    {
        $complimentary = (string) __('panel.items.complimentary');

        return [
            TextColumn::make('name')
                ->label(__('panel.shared.name'))
                ->description(fn (MenuItem $record): ?string => $record->description),

            TextColumn::make('kind')
                ->label(__('panel.items.type'))
                ->badge()
                ->formatStateUsing(fn (MenuItemKind $state): string => $state->label())
                ->icon(fn (MenuItemKind $state): Heroicon => $state->icon())
                ->color(fn (MenuItemKind $state): string => $state->color()),

            // Formatted here rather than in the browser: a panel is server
            // rendered, and the currency is resolved once for the table.
            TextColumn::make('price')
                ->label(__('panel.items.price'))
                ->formatStateUsing(fn (MenuItem $record): string => $record->isComplimentary()
                    ? $complimentary
                    : $record->formattedPrice($currency))
                ->description(fn (MenuItem $record): ?string => $record->formattedComparePrice($currency))
                ->alignEnd(),

            TextColumn::make('availability')
                ->label(__('panel.items.availability'))
                ->badge()
                ->formatStateUsing(fn (ItemAvailability $state): string => $state->label())
                ->color(fn (ItemAvailability $state): string => $state->color()),

            // A dash when nobody counts it, red at none left.
            TextColumn::make('stock_quantity')
                ->label(__('panel.stock.in_stock'))
                ->numeric()
                ->placeholder('—')
                ->color(fn (?int $state): ?string => $state === 0 ? 'danger' : null)
                ->alignEnd(),
        ];
    }

    /**
     * An item edited in a full-width slide-over, add-on groups and tax included — the featured items' table edits the same way.
     */
    public static function editAction(): EditAction
    {
        return EditAction::make()
            ->iconButton()
            ->icon(Heroicon::OutlinedPencilSquare)
            ->tooltip(__('panel.arrangement.edit'))
            ->slideOver()
            ->modalWidth(Width::Full)
            ->mutateRecordDataUsing(fn (array $data, MenuItem $record): array => MenuItemForm::fill($data, $record))
            ->using(fn (array $data, MenuItem $record): MenuItem => MenuItemForm::update($record, $data));
    }

    private function category(): MenuCategory
    {
        $category = $this->getOwnerRecord();

        return $category instanceof MenuCategory ? $category : throw new LogicException('The items table requires a category.');
    }
}
