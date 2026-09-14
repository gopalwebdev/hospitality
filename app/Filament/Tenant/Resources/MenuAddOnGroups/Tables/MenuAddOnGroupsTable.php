<?php

namespace App\Filament\Tenant\Resources\MenuAddOnGroups\Tables;

use App\Filament\Schemas\TranslatedFields;
use App\Filament\Tenant\Resources\MenuAddOnGroups\Schemas\MenuAddOnGroupForm;
use App\Filament\Tenant\Resources\Menus\Schemas\MenuSubCategoryForm;
use App\Models\MenuAddOnGroup;
use App\Models\MenuAddOnOption;
use App\Models\MenuItem;
use App\Models\MenuItemAddOnGroup;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MenuAddOnGroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('panel.shared.name'))
                    // A translated column holds a JSON document, so searching
                    // and sorting have to name the language they mean.
                    ->searchable(query: fn (Builder $query, string $search): Builder => TranslatedFields::search($query, 'name', $search))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => TranslatedFields::sort($query, 'name', $direction))
                    // What is in it, under its name, so a group reads without being opened.
                    ->description(fn (MenuAddOnGroup $record): string => $record->options
                        ->map(fn (MenuAddOnOption $option): string => $option->name)
                        ->join(' · ')),

                TextColumn::make('options_count')
                    ->label(__('panel.add_on_groups.options'))
                    ->counts('options')
                    ->alignEnd(),

                TextColumn::make('item_links_count')
                    ->label(__('panel.add_on_groups.used_on'))
                    ->counts('itemLinks')
                    ->formatStateUsing(fn (int $state): string => trans_choice('panel.add_on_groups.items_count', $state))
                    ->icon(Heroicon::OutlinedListBullet)
                    ->badge()
                    // A group on no item offers a guest nothing, which is worth seeing.
                    ->color(fn (int $state): string => $state === 0 ? 'gray' : 'info')
                    ->sortable(),
            ])
            ->recordActions([
                self::attachToItemsAction(),

                EditAction::make()
                    ->iconButton()
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->tooltip(__('panel.arrangement.edit'))
                    // The group beside its table of options.
                    ->modalWidth(Width::SevenExtraLarge)
                    ->mutateRecordDataUsing(fn (array $data, MenuAddOnGroup $record): array => MenuAddOnGroupForm::fillTranslations($data, $record)),

                DeleteAction::make()
                    ->iconButton()
                    ->icon(Heroicon::OutlinedTrash)
                    ->tooltip(__('panel.arrangement.delete'))
                    ->modalDescription(__('panel.add_on_groups.delete_warning')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            // A library, read by name: a group's place is decided on each item it is linked to.
            ->defaultSort(fn (Builder $query): Builder => TranslatedFields::sort($query, 'name', 'asc'))
            // Every row previews its options, so they are loaded once for the
            // page — with every column, because the edit form's options
            // repeater fills from this loaded relation rather than querying
            // again, and would fill each price and quantity from nothing.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'options' => fn ($options) => $options->orderBy('position')->orderBy('id'),
            ]))
            ->emptyStateHeading(__('panel.add_on_groups.empty_heading'))
            ->emptyStateDescription(__('panel.add_on_groups.empty_description'))
            ->emptyStateIcon(Heroicon::OutlinedAdjustmentsHorizontal);
    }

    /**
     * Offer this group on many items at once, each getting it after the groups it already has.
     *
     * The item form links a group to one item; this is how a new "Spice level"
     * reaches every curry. Items that already offer the group are not listed,
     * and are skipped if the request names them anyway.
     */
    private static function attachToItemsAction(): Action
    {
        return Action::make('attachToItems')
            ->label(__('panel.add_on_groups.attach'))
            ->iconButton()
            ->icon(Heroicon::OutlinedLink)
            ->color('info')
            ->tooltip(__('panel.add_on_groups.attach'))
            ->modalHeading(fn (MenuAddOnGroup $record): string => (string) __('panel.add_on_groups.attach_heading', ['name' => $record->name]))
            ->modalSubmitActionLabel(__('panel.add_on_groups.attach'))
            ->authorize('update')
            ->schema(fn (MenuAddOnGroup $record): array => [
                Select::make('items')
                    ->label(__('panel.add_on_groups.items'))
                    ->options(fn (): array => self::unlinkedItemOptions($record->getKey()))
                    ->multiple()
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data, MenuAddOnGroup $record): void {
                // Only this tenant's items that do not offer the group yet,
                // whatever ids the request carried.
                $itemIds = MenuItem::query()
                    ->where('tenant_id', $record->tenant_id)
                    ->whereKey(array_map(intval(...), (array) $data['items']))
                    ->whereDoesntHave('addOnGroupLinks', fn (Builder $links): Builder => $links->where('menu_add_on_group_id', $record->getKey()))
                    ->pluck('id');

                $lastPositions = MenuItemAddOnGroup::query()
                    ->whereIn('menu_item_id', $itemIds)
                    ->groupBy('menu_item_id')
                    ->selectRaw('menu_item_id, max(position) as last_position')
                    ->pluck('last_position', 'menu_item_id');

                foreach ($itemIds as $itemId) {
                    $record->itemLinks()
                        ->make(['position' => $lastPositions->has($itemId) ? ((int) $lastPositions->get($itemId)) + 1 : 0])
                        ->forceFill(['menu_item_id' => $itemId, 'tenant_id' => $record->tenant_id])
                        ->save();
                }

                Notification::make()
                    ->title(trans_choice('panel.add_on_groups.attached', $itemIds->count()))
                    ->success()
                    ->send();
            });
    }

    /**
     * This tenant's items that do not offer the group yet, under the category each is filed in.
     *
     * Service requests included: which pillow is a choice too. Headings are
     * "Menu · Category › Sub-category" in menu order, so two items of one name
     * on different menus are told apart.
     *
     * @return array<string, array<int, string>>
     */
    private static function unlinkedItemOptions(int $groupId): array
    {
        $tenantId = self::tenantKey();
        $categories = MenuSubCategoryForm::categoryOptionsForTenant($tenantId);

        // once(): Filament asks a select for its options more than once while it
        // builds and validates one form. Keyed on the group's id rather than the
        // record, which may be a different instance each time it is resolved.
        return once(function () use ($tenantId, $groupId, $categories): array {
            $items = MenuItem::query()
                ->where('tenant_id', $tenantId)
                ->whereDoesntHave('addOnGroupLinks', fn (Builder $links): Builder => $links->where('menu_add_on_group_id', $groupId))
                ->orderBy('position')
                ->orderBy('id')
                ->get(['id', 'name', 'menu_category_id'])
                ->groupBy('menu_category_id');

            $options = [];

            foreach ($categories as $categoryId => $label) {
                foreach ($items->get($categoryId, []) as $item) {
                    $options[$label][$item->getKey()] = $item->name;
                }
            }

            return $options;
        });
    }

    /**
     * The tenant the panel is serving, if there is one.
     */
    private static function tenantKey(): ?int
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Tenant ? $tenant->getKey() : null;
    }
}
