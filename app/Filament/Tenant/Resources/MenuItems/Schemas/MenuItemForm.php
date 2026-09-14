<?php

namespace App\Filament\Tenant\Resources\MenuItems\Schemas;

use App\Enums\Currency;
use App\Enums\Diet;
use App\Filament\Schemas\PricingFields;
use App\Filament\Schemas\TranslatedFields;
use App\Filament\Tenant\Resources\MenuAddOnGroups\Schemas\MenuAddOnGroupForm;
use App\Filament\Tenant\Resources\Menus\Schemas\MenuSubCategoryForm;
use App\Models\MenuAddOnGroup;
use App\Models\MenuItem;
use App\Models\Tenant;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
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

    /**
     * `$categoryId` is where a new item is filed unless changed: the category whose table it is added from.
     *
     * Laid out for the full-width modal every item opens in: what the item is
     * across two thirds, its price and tax stacked in the last third, and the
     * add-on groups it is customised with in a row of their own underneath. The
     * grid answers to the width of its container rather than the screen, so the
     * same form stacks into one column wherever it is given less room.
     */
    public static function configure(Schema $schema, ?int $categoryId = null): Schema
    {
        $currency = PricingFields::currency();

        return $schema
            ->columns(1)
            ->components([
                TranslatedFields::localeSwitcher(),

                Grid::make(['default' => 1, '@4xl' => 3])
                    ->gridContainer()
                    ->schema([
                        self::itemSection($categoryId)
                            ->columnSpan(['default' => 1, '@4xl' => 2]),

                        Grid::make(1)
                            ->columnSpan(1)
                            ->schema([
                                self::priceSection($currency),
                                self::taxSection(),
                            ]),

                        self::addOnGroupsSection()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * What the item is: its name and diet mark, where it is filed, and what a guest reads under it.
     */
    private static function itemSection(?int $categoryId): Section
    {
        return Section::make(__('panel.items.item'))
            ->icon(Heroicon::OutlinedListBullet)
            ->compact()
            ->columns(6)
            ->schema([
                ...self::spanning(TranslatedFields::text(
                    'name',
                    __('panel.shared.name'),
                    maxLength: 120,
                    // Unique within the category rather than the whole
                    // tenant: a lunch and a dinner menu may both list a
                    // "Paneer Tikka", and so may two sub-categories of
                    // one category.
                    uniqueWithin: fn (Get $get): Builder => MenuItem::query()
                        ->where('menu_category_id', $get('menu_category_id')),
                    uniqueMessage: __('panel.items.unique'),
                ), 4),

                // Everything but a service request carries the veg /
                // egg / non-veg mark. A hidden field is not saved, and
                // MenuItemObserver clears the diet of an item that has
                // just become a service request.
                Select::make('diet')
                    ->label(__('panel.items.diet'))
                    ->options(Diet::options())
                    ->default(Diet::Vegetarian->value)
                    ->native(false)
                    ->visible(fn (Get $get): bool => ! (bool) $get('is_service_request'))
                    ->required(fn (Get $get): bool => ! (bool) $get('is_service_request'))
                    ->columnSpan(2),

                // One select, because an item is filed under exactly one
                // category — a section or one of its subdivisions, both
                // offered here as "Lunch · Biryani › Chicken". Only this
                // tenant's are listed, and MenuItemObserver refuses
                // anything else even if the id is tampered with.
                Select::make('menu_category_id')
                    ->label(__('panel.items.section'))
                    ->options(fn (): array => self::sectionOptions())
                    ->default($categoryId)
                    ->required()
                    ->searchable()
                    ->preload()
                    ->prefixIcon(Heroicon::OutlinedRectangleStack)
                    ->columnSpan(4),

                // Live, because it decides whether the diet is asked for at all.
                Toggle::make('is_service_request')
                    ->label(__('panel.items.is_service_request'))
                    ->default(false)
                    ->inline(false)
                    ->live()
                    ->columnSpan(2),

                ...self::spanning(TranslatedFields::textarea('description', __('panel.shared.description'), maxLength: 500, rows: 2), 6),
            ]);
    }

    /**
     * What a guest pays, whether they can have it now, whether the menu leads with it, and how many one order may hold.
     */
    private static function priceSection(Currency $currency): Section
    {
        return Section::make(__('panel.items.price_section'))
            ->icon(Heroicon::OutlinedBanknotes)
            ->compact()
            ->columns(2)
            ->schema([
                // Typed and shown in major units, stored as an integer count of
                // minor units — PricingFields does the conversion in one place for
                // this form and the combo one, so no float ever reaches the
                // database. Zero is a real price, and the guest app reads it as
                // complimentary.
                PricingFields::price($currency),
                PricingFields::compareAtPrice($currency),
                PricingFields::availability(),

                // Featuring puts an item in the row above the sections on the
                // guest's menu screen. The order those are read in is dragged on
                // the menu's own page, not typed here.
                Toggle::make('is_featured')
                    ->label(__('panel.items.is_featured'))
                    ->default(false)
                    ->inline(false),

                ...PricingFields::quantityLimits(),
            ]);
    }

    /**
     * The GST rate and code, open beside the price rather than folded away.
     */
    private static function taxSection(): Section
    {
        return Section::make(__('panel.items.tax_section'))
            ->icon(Heroicon::OutlinedReceiptPercent)
            ->compact()
            ->columns(2)
            ->schema([
                PricingFields::taxRatePercentage(PricingFields::tenantTaxRateBasisPoints()),
                PricingFields::hsnCode(),
            ]);
    }

    /**
     * The add-on groups a guest customises this item with, in the order they read them.
     *
     * Each row names a group from the tenant's library — the Add-on groups page —
     * so a group offered on twenty items is edited once. A row is only the link
     * and its place on this item, which is why the repeater is bound to the links
     * rather than to the groups. A group that does not exist yet can be made from
     * the select without leaving the item.
     *
     * A repeater bound to the relationship, so the links are written in the same
     * save as the item. The rule in .ai/rules/filament.md against
     * `->relationship()` is about Spatie roles and permissions, whose cache is
     * only flushed by syncRoles()/syncPermissions(); this is a plain hasMany.
     */
    private static function addOnGroupsSection(): Section
    {
        return Section::make(__('panel.add_on_groups.plural'))
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->compact()
            ->schema([
                Repeater::make('addOnGroupLinks')
                    ->relationship()
                    ->hiddenLabel()
                    ->table([
                        TableColumn::make(__('panel.add_on_groups.section'))->markAsRequired(),
                    ])
                    ->schema([
                        Select::make('menu_add_on_group_id')
                            ->label(__('panel.add_on_groups.section'))
                            ->options(fn (): array => MenuAddOnGroupForm::groupOptions())
                            ->required()
                            ->searchable()
                            // A group is offered on an item once; nothing but this
                            // refuses a second row.
                            ->distinct()
                            ->validationMessages(['distinct' => __('panel.add_on_groups.duplicate')])
                            ->createOptionForm(fn (Schema $schema): Schema => MenuAddOnGroupForm::configure($schema->model(MenuAddOnGroup::class)))
                            ->createOptionUsing(fn (array $data, Schema $schema): int => MenuAddOnGroupForm::createFromItemForm($data, $schema)),
                    ])
                    ->orderColumn('position')
                    // Most items have none, and a blank row waiting to be filled
                    // in would make every save fail validation until it was deleted.
                    ->defaultItems(0)
                    ->addActionLabel(__('panel.add_on_groups.link'))
                    ->reorderable()
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Make a set of translated inputs span as many columns of their section as given.
     *
     * Only one language is on screen at a time, so a translated field is a
     * single box, and it is sized like one.
     *
     * @param  list<TextInput|Textarea>  $fields
     * @return list<TextInput|Textarea>
     */
    private static function spanning(array $fields, int $columns): array
    {
        return array_map(
            static fn (TextInput|Textarea $field): TextInput|Textarea => $field->columnSpan($columns),
            $fields,
        );
    }

    /**
     * Put every language back into the form when an item is edited.
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
     * The currency this tenant prices in.
     */
    public static function currency(): Currency
    {
        return PricingFields::currency();
    }

    /**
     * This tenant's categories, labelled with the menu they sit on.
     *
     * Two menus may each have a "Starters", so the menu has to be part of the
     * label or the select offers the same word twice.
     *
     * @return array<int, string>
     */
    public static function sectionOptions(): array
    {
        return MenuSubCategoryForm::categoryOptionsForTenant(self::tenantKey());
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
