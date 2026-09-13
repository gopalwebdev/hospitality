<?php

namespace App\Filament\Restaurant\Resources\MenuItems\Schemas;

use App\Enums\Currency;
use App\Enums\FoodType;
use App\Filament\Restaurant\Resources\Menus\Schemas\MenuSubCategoryForm;
use App\Filament\Schemas\PricingFields;
use App\Filament\Schemas\TranslatedFields;
use App\Models\MenuItem;
use App\Models\Restaurant;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class MenuItemForm
{
    /**
     * The columns this form edits in more than one language.
     *
     * @var list<string>
     */
    public const array TRANSLATED = ['name', 'description'];

    public static function configure(Schema $schema): Schema
    {
        $currency = PricingFields::currency();

        return $schema
            ->columns(1)
            ->components([
                TranslatedFields::localeSwitcher(),

                Section::make(__('panel.items.dish'))
                    ->icon(Heroicon::OutlinedListBullet)
                    ->schema([
                        // One select, because a dish is filed under exactly one
                        // category — a section or one of its subdivisions, both
                        // offered here as "Lunch · Biryani › Chicken". Only this
                        // restaurant's are listed, and the composite foreign key
                        // refuses anything else even if the id is tampered with.
                        Select::make('menu_category_id')
                            ->label(__('panel.items.section'))
                            ->options(fn (): array => self::sectionOptions())
                            ->required()
                            ->searchable()
                            ->preload()
                            ->prefixIcon(Heroicon::OutlinedRectangleStack),

                        ...self::spanningFull(TranslatedFields::text(
                            'name',
                            __('panel.shared.name'),
                            maxLength: 120,
                            // Unique within the category rather than the whole
                            // restaurant, matching the database index: a lunch
                            // and a dinner menu may both list a "Paneer Tikka",
                            // and so may two sub-categories of one category.
                            uniqueWithin: fn (Get $get): Builder => MenuItem::query()
                                ->where('menu_category_id', $get('menu_category_id')),
                            uniqueMessage: __('panel.items.unique'),
                        )),

                        Select::make('food_type')
                            ->label(__('panel.items.food_type'))
                            ->options(FoodType::options())
                            ->required()
                            ->default(FoodType::Vegetarian->value)
                            ->native(false),

                        ...self::spanningFull(TranslatedFields::textarea('description', __('panel.shared.description'), maxLength: 500, rows: 3)),
                    ])
                    ->columns(2),

                Section::make(__('panel.items.price_section'))
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->schema([
                        // Typed and shown in major units, stored as an integer
                        // count of minor units — PricingFields does the
                        // conversion in one place for this form and the combo
                        // one, so no float ever reaches the database.
                        PricingFields::price($currency),
                        PricingFields::compareAtPrice($currency),
                        PricingFields::availability(),

                        // Featuring puts a dish in the row above the sections
                        // on the guest's menu screen. The order those are read
                        // in is dragged on the menu's own page, not typed here.
                        Toggle::make('is_featured')
                            ->label(__('panel.items.is_featured'))
                            ->default(false)
                            ->inline(false),
                    ])
                    ->columns(2),

                Section::make(__('panel.items.tax_section'))
                    ->icon(Heroicon::OutlinedReceiptPercent)
                    ->schema([
                        PricingFields::taxRatePercentage(PricingFields::restaurantTaxRateBasisPoints()),
                        PricingFields::hsnCode(),
                    ])
                    ->columns(2)
                    // Almost every dish is taxed at the restaurant's own rate
                    // and carries no code, so this opens closed and is expanded
                    // by the dishes that genuinely differ.
                    ->collapsed(fn (?MenuItem $record): bool => blank($record?->tax_rate_basis_points) && blank($record?->hsn_code)),

                Section::make(__('panel.additions.section'))
                    ->icon(Heroicon::OutlinedPlusCircle)
                    ->schema([
                        self::additions($currency),
                    ])
                    ->collapsed(fn (?MenuItem $record): bool => $record?->additions()->doesntExist() ?? true),
            ]);
    }

    /**
     * Make a set of translated inputs span the section they sit in.
     *
     * Only one language is on screen at a time now, so a translated field is a
     * single box — and a single box in a two-column grid would leave the other
     * half of the line empty.
     *
     * @param  list<TextInput|Textarea>  $fields
     * @return list<TextInput|Textarea>
     */
    private static function spanningFull(array $fields): array
    {
        return array_map(
            static fn (TextInput|Textarea $field): TextInput|Textarea => $field->columnSpanFull(),
            $fields,
        );
    }

    /**
     * The extras a dish can be ordered with, as a table.
     *
     * A table rather than a stack of collapsible cards: every addition is a
     * name, a price and two small settings, so a row says everything a card
     * did in a fraction of the height — a dish with eight extras used to be a
     * page of accordions. Reordering and the per-row delete are unchanged.
     *
     * A repeater bound to the relationship, so additions are written in the
     * same save as the dish they belong to. The rule in .ai/rules/filament.md
     * against `->relationship()` is about Spatie roles and permissions, whose
     * cache is only flushed by syncRoles()/syncPermissions(); this is a plain
     * hasMany with no cache behind it, and the rule does not apply.
     *
     * Additions cannot be dragged from one dish to another: they are edited
     * inside the dish that owns them, and the composite foreign key on
     * (menu_item_id, tenant_id) is what makes that structural rather than a
     * convention.
     */
    private static function additions(Currency $currency): Repeater
    {
        return Repeater::make('additions')
            ->relationship()
            ->hiddenLabel()
            ->table([
                TableColumn::make(__('panel.additions.label'))->markAsRequired(),
                TableColumn::make(__('panel.additions.price'))->width('10rem'),
                TableColumn::make(__('panel.items.tax_rate'))->width('9rem'),
                TableColumn::make(__('panel.additions.is_available'))->width('7rem')->alignment(Alignment::Center),
            ])
            ->schema([
                // Only the switched-to language is on screen, exactly as
                // everywhere else — an addition's name is guest-facing text and
                // is translated like the dish above it.
                ...TranslatedFields::text('name', __('panel.additions.label'), maxLength: 64),

                TextInput::make('price')
                    ->label(__('panel.additions.price'))
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(99999)
                    ->step(0.01)
                    ->default(0)
                    ->prefix($currency->symbol()),

                TextInput::make('tax_rate_percentage')
                    ->label(__('panel.items.tax_rate'))
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->step(0.01)
                    ->suffix('%')
                    ->placeholder(PricingFields::formatRate(PricingFields::restaurantTaxRateBasisPoints())),

                Toggle::make('is_available')
                    ->label(__('panel.additions.is_available'))
                    ->default(true),
            ])
            ->orderColumn('position')
            // Most dishes have none, and a blank row waiting to be filled in
            // would make every save fail validation until it was deleted.
            ->defaultItems(0)
            ->addActionLabel(__('panel.additions.add'))
            ->reorderable()
            ->columnSpanFull()
            // The repeater edits a major-unit price the same way the dish above
            // does, and each row is converted on its own way in and out.
            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => self::storeAddition($data, $currency))
            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => self::storeAddition($data, $currency))
            ->mutateRelationshipDataBeforeFillUsing(fn (array $data): array => self::fillAddition($data, $currency));
    }

    /**
     * Turn an addition's typed price and rate into what gets stored.
     *
     * An addition has no compare-at price — it is a delta on the dish, and
     * "was +₹40, now +₹30" is not something a menu says — so this is its own
     * small conversion rather than PricingFields::store().
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function storeAddition(array $data, Currency $currency): array
    {
        $data['price_minor_units'] = $currency->toMinorUnits($data['price'] ?? 0);

        $data['tax_rate_basis_points'] = blank($data['tax_rate_percentage'] ?? null)
            ? null
            : PricingFields::toBasisPoints($data['tax_rate_percentage']);

        unset($data['price'], $data['tax_rate_percentage']);

        return $data;
    }

    /**
     * Turn a stored addition back into the values the form edits.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function fillAddition(array $data, Currency $currency): array
    {
        $data['price'] = $currency->toMajorUnits((int) ($data['price_minor_units'] ?? 0));

        $data['tax_rate_percentage'] = blank($data['tax_rate_basis_points'] ?? null)
            ? null
            : PricingFields::toPercentage((int) $data['tax_rate_basis_points']);

        return $data;
    }

    /**
     * Put every language back into the form when a dish is edited.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillTranslations(array $data, MenuItem $record): array
    {
        return $record->fillTranslationsInto($data, ...self::TRANSLATED);
    }

    /**
     * Turn the typed prices and rate into what gets stored.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function storePricing(array $data): array
    {
        return PricingFields::store($data, self::currency());
    }

    /**
     * Turn what is stored back into the values the form edits.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillPricing(array $data): array
    {
        return PricingFields::fill($data, self::currency());
    }

    /**
     * The currency this restaurant prices in.
     */
    public static function currency(): Currency
    {
        return PricingFields::currency();
    }

    /**
     * This restaurant's categories, labelled with the menu they sit on.
     *
     * Two menus may each have a "Starters", so the menu has to be part of the
     * label or the select offers the same word twice.
     *
     * @return array<int, string>
     */
    public static function sectionOptions(): array
    {
        return MenuSubCategoryForm::categoryOptionsForRestaurant(self::tenantKey());
    }

    /**
     * The restaurant the panel is serving, if there is one.
     */
    private static function tenantKey(): ?int
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Restaurant ? $tenant->getKey() : null;
    }
}
