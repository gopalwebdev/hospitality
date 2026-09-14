<?php

namespace App\Filament\Tenant\Resources\Charges\Schemas;

use App\Enums\ChargeCalculation;
use App\Filament\Schemas\PricingFields;
use App\Filament\Schemas\TranslatedFields;
use App\Filament\Tenant\Resources\Menus\Schemas\MenuCategoryForm;
use App\Models\Charge;
use App\Models\Menu;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

/**
 * A charge: what it is called, what it adds, and which menus it is on.
 *
 * The number is typed the way a person says it — "10" percent, "20" rupees —
 * and stored the way the rest of the application stores its kind: a rate in
 * basis points, an amount in minor units. storeValue() and fillValue() are the
 * only place that conversion happens, through PricingFields, so the rounding
 * is done once.
 */
class ChargeForm
{
    /**
     * The columns this form edits in more than one language.
     *
     * @var list<string>
     */
    public const array TRANSLATED = ['name'];

    public static function configure(Schema $schema): Schema
    {
        $currency = PricingFields::currency();

        return $schema
            ->columns(1)
            ->components([
                TranslatedFields::localeSwitcher(),

                Section::make(__('panel.charges.section'))
                    ->icon(Heroicon::OutlinedReceiptPercent)
                    ->schema([
                        ...TranslatedFields::text(
                            'name',
                            __('panel.shared.name'),
                            maxLength: 64,
                            // Unique within the tenant: two charges of one name
                            // would read to a guest as the same line twice.
                            uniqueWithin: fn (): Builder => Charge::query()
                                ->where('tenant_id', Filament::getTenant()?->getKey()),
                            uniqueMessage: __('panel.charges.unique'),
                        ),

                        // Live, because it decides which of the two numbers
                        // below is asked for.
                        Radio::make('calculation')
                            ->label(__('panel.charges.calculation'))
                            ->options(ChargeCalculation::options())
                            ->default(ChargeCalculation::Percentage->value)
                            ->required()
                            ->inline()
                            ->live(),

                        // A hidden field is not saved, and ChargeObserver
                        // clears whichever number the calculation does not use.
                        TextInput::make('rate_percentage')
                            ->label(__('panel.charges.rate'))
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step(0.01)
                            ->suffix('%')
                            ->visible(fn (Get $get): bool => self::calculation($get) === ChargeCalculation::Percentage)
                            ->required(fn (Get $get): bool => self::calculation($get) === ChargeCalculation::Percentage),

                        TextInput::make('amount')
                            ->label(__('panel.charges.amount'))
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(99999)
                            ->step(0.01)
                            ->prefix($currency->symbol())
                            ->visible(fn (Get $get): bool => self::calculation($get) === ChargeCalculation::FixedAmount)
                            ->required(fn (Get $get): bool => self::calculation($get) === ChargeCalculation::FixedAmount),
                    ]),

                Section::make(__('panel.charges.where_section'))
                    ->icon(Heroicon::OutlinedBookOpen)
                    ->schema([
                        // Bound to the relationship, so the list is written in
                        // the same save as the charge. The rule in
                        // .ai/rules/filament.md against ->relationship() is
                        // about Spatie roles and permissions; this is a plain
                        // belongsToMany. The options are this tenant's menus,
                        // and the rule below refuses any other id even if the
                        // request is tampered with — charge_menu carries no
                        // tenant of its own for an observer to check. A charge
                        // is on exactly these menus, so a new one starts on
                        // every menu and is taken off the ones it is not for.
                        Select::make('menus')
                            ->label(__('panel.charges.menus'))
                            ->multiple()
                            ->relationship('menus', 'name')
                            ->options(fn (): array => MenuCategoryForm::menuOptions())
                            // A menu's name is a translated column; its accessor
                            // answers in the panel's language, as the options do.
                            ->getOptionLabelFromRecordUsing(fn (Menu $record): string => $record->name)
                            ->default(fn (): array => array_keys(MenuCategoryForm::menuOptions()))
                            ->searchable()
                            ->preload()
                            ->required()
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                $offered = array_keys(MenuCategoryForm::menuOptions());

                                foreach ((array) $value as $menuId) {
                                    if (! in_array((int) $menuId, $offered, strict: true)) {
                                        $fail(__('panel.charges.menus_invalid'));

                                        return;
                                    }
                                }
                            }),

                        Toggle::make('is_active')
                            ->label(__('panel.charges.is_active'))
                            ->default(true)
                            ->inline(false),
                    ]),
            ]);
    }

    /**
     * Put every language back into the form when a charge is edited.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillTranslations(array $data, Charge $record): array
    {
        return $record->fillTranslationsInto($data, ...self::TRANSLATED);
    }

    /**
     * Turn the typed number into the column its calculation reads.
     *
     * A blank number is stored as null rather than zero: only the column the
     * calculation uses is filled, and ChargeObserver refuses a charge whose
     * number is missing.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function storeValue(array $data): array
    {
        $data['rate_basis_points'] = blank($data['rate_percentage'] ?? null)
            ? null
            : PricingFields::toBasisPoints($data['rate_percentage']);

        $data['amount_minor_units'] = blank($data['amount'] ?? null)
            ? null
            : PricingFields::currency()->toMinorUnits($data['amount']);

        unset($data['rate_percentage'], $data['amount']);

        return $data;
    }

    /**
     * Turn what is stored back into the number the form edits.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillValue(array $data): array
    {
        $data['rate_percentage'] = blank($data['rate_basis_points'] ?? null)
            ? null
            : PricingFields::toPercentage((int) $data['rate_basis_points']);

        $data['amount'] = blank($data['amount_minor_units'] ?? null)
            ? null
            : PricingFields::currency()->toMajorUnits((int) $data['amount_minor_units']);

        return $data;
    }

    /**
     * The calculation picked on the form, whether its state is the value or the enum.
     */
    private static function calculation(Get $get): ?ChargeCalculation
    {
        $calculation = $get('calculation');

        if ($calculation instanceof ChargeCalculation) {
            return $calculation;
        }

        return is_string($calculation) ? ChargeCalculation::tryFrom($calculation) : null;
    }
}
