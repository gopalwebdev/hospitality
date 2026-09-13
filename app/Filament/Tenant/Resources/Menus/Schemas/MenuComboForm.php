<?php

namespace App\Filament\Restaurant\Resources\Menus\Schemas;

use App\Filament\Schemas\PricingFields;
use App\Filament\Schemas\TranslatedFields;
use App\Models\MenuCombo;
use App\Models\MenuItem;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

/**
 * A bundle: what it is called, what it costs, and what is in it.
 *
 * The contents are a table repeater rather than a stack of cards, for the same
 * reason the additions on a dish are: every line is a dish and a number, and a
 * table shows ten of them in the space three cards would take.
 *
 * A combo's price is typed, never derived from its contents. The point of a
 * combo is that it costs less than the sum of its parts, and a derived price
 * would either be that sum or a discount rule nobody asked for. What the parts
 * come to separately is shown beside the price on the table instead, so the
 * saving is visible without being enforced.
 */
class MenuComboForm
{
    /**
     * The columns this form edits in more than one language.
     *
     * @var list<string>
     */
    public const array TRANSLATED = ['name', 'description'];

    public static function configure(Schema $schema, ?int $menuId = null): Schema
    {
        $currency = PricingFields::currency();

        return $schema
            ->columns(1)
            ->components([
                TranslatedFields::localeSwitcher(),

                Section::make(__('panel.combos.section'))
                    ->icon(Heroicon::OutlinedSparkles)
                    ->schema([
                        ...TranslatedFields::text(
                            'name',
                            __('panel.shared.name'),
                            maxLength: 120,
                            // Unique within the menu, matching the expression
                            // index — a lunch and a dinner card may both offer
                            // a "Family Feast".
                            uniqueWithin: fn (): Builder => MenuCombo::query()
                                ->where('menu_id', $menuId),
                            uniqueMessage: __('panel.combos.unique'),
                        ),

                        ...TranslatedFields::textarea('description', __('panel.shared.description'), maxLength: 300, rows: 2),
                    ]),

                Section::make(__('panel.items.price_section'))
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->schema([
                        PricingFields::price($currency),
                        PricingFields::compareAtPrice($currency),
                        PricingFields::taxRatePercentage(PricingFields::restaurantTaxRateBasisPoints()),
                        PricingFields::availability(),
                    ])
                    ->columns(2),

                Section::make(__('panel.combos.contents'))
                    ->icon(Heroicon::OutlinedListBullet)
                    ->schema([
                        self::contents($menuId),
                    ]),
            ]);
    }

    /**
     * The dishes in the bundle, as a table of dish and quantity.
     *
     * Bound to the relationship, so the contents are written in the same save
     * as the combo. The rule in .ai/rules/filament.md against `->relationship()`
     * is about Spatie roles and permissions, whose cache is only flushed by
     * syncRoles()/syncPermissions(); this is a plain hasMany with no cache
     * behind it, and the rule does not apply.
     */
    private static function contents(?int $menuId): Repeater
    {
        return Repeater::make('comboItems')
            ->relationship()
            ->hiddenLabel()
            ->table([
                TableColumn::make(__('panel.combos.dish'))->markAsRequired(),
                TableColumn::make(__('panel.combos.quantity'))->width('9rem'),
            ])
            ->schema([
                Select::make('menu_item_id')
                    ->options(fn (): array => self::dishOptions($menuId))
                    ->required()
                    ->searchable()
                    ->preload()
                    // A dish appears in a combo once, with a quantity. The
                    // unique key on (menu_combo_id, menu_item_id) refuses a
                    // second row anyway; this is where an admin is told.
                    ->distinct()
                    ->validationMessages(['distinct' => __('panel.combos.duplicate_dish')]),

                TextInput::make('quantity')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(99)
                    ->default(1)
                    ->required(),
            ])
            ->orderColumn('position')
            ->defaultItems(0)
            ->addActionLabel(__('panel.combos.add_dish'))
            ->reorderable()
            ->columnSpanFull();
    }

    /**
     * The dishes this combo may contain.
     *
     * Every dish on the same menu, labelled with the section it sits in so two
     * dishes of the same name in different sections are told apart.
     *
     * @return array<int, string>
     */
    public static function dishOptions(?int $menuId): array
    {
        if ($menuId === null) {
            return [];
        }

        // once(): a repeater asks every row's select for its options.
        return once(fn (): array => MenuItem::query()
            ->onMenu($menuId)
            ->with(['menuCategory:id,parent_id,name', 'menuCategory.parent:id,name'])
            ->inMenuOrder()
            ->get()
            ->mapWithKeys(fn (MenuItem $item): array => [
                $item->getKey() => sprintf('%s · %s', $item->menuCategory->path(), $item->name),
            ])
            ->all());
    }

    /**
     * Put every language back into the form when a combo is edited.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillTranslations(array $data, MenuCombo $record): array
    {
        return $record->fillTranslationsInto($data, ...self::TRANSLATED);
    }
}
