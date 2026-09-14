<?php

namespace App\Filament\Tenant\Resources\MenuAddOnGroups\Schemas;

use App\Enums\Currency;
use App\Filament\Schemas\PricingFields;
use App\Filament\Schemas\TranslatedFields;
use App\Models\MenuAddOnGroup;
use App\Models\Tenant;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Once;
use LogicException;

/**
 * An add-on group: what a guest reads above it, how many picks it asks for, and the options they pick from.
 *
 * The rule belongs to the group wherever it is linked: at least `min_selections`
 * picks (none makes the group optional) and at most `max_selections` (blank is
 * no limit), each option counted by its quantity — two of "Extra cheese" are two
 * picks toward "up to 3". An option's own `max_quantity` caps how many of it one
 * item takes.
 *
 * Prices are typed in major units and rates as a percentage, converted row by
 * row on the way in and out through PricingFields, as an item's are.
 */
class MenuAddOnGroupForm
{
    /**
     * The columns this form edits in more than one language.
     *
     * @var list<string>
     */
    public const array TRANSLATED = ['name'];

    /**
     * The group's own fields on top, its options table below — stacked, so the
     * table gets the modal's full width rather than sharing it with a sidebar.
     */
    public static function configure(Schema $schema): Schema
    {
        $currency = PricingFields::currency();

        return $schema
            ->columns(1)
            ->components([
                TranslatedFields::localeSwitcher(),
                self::groupSection(),
                self::optionsSection($currency),
            ]);
    }

    /**
     * The group's name and the rule a guest's picks have to meet, in one row.
     */
    private static function groupSection(): Section
    {
        return Section::make(__('panel.add_on_groups.section'))
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->compact()
            ->columns(6)
            ->schema([
                ...array_map(
                    static fn (TextInput $field): TextInput => $field->columnSpan(3),
                    TranslatedFields::text(
                        'name',
                        __('panel.shared.name'),
                        maxLength: 80,
                        // Unique within the tenant: two groups of one name would
                        // be told apart nowhere an admin picks one.
                        uniqueWithin: fn (): Builder => MenuAddOnGroup::query()
                            ->where('tenant_id', self::tenantKey()),
                        uniqueMessage: __('panel.add_on_groups.unique'),
                    ),
                ),

                // Not a column: min_selections is, and this is the way an admin
                // thinks of it. Switching it on asks for one pick, and off makes
                // the group optional again.
                Toggle::make('is_required')
                    ->label(__('panel.add_on_groups.is_required'))
                    ->inline(false)
                    ->live()
                    ->dehydrated(false)
                    ->afterStateHydrated(fn (Toggle $component, Get $get): Toggle => $component->state((int) $get('min_selections') >= 1))
                    ->afterStateUpdated(fn (bool $state, Set $set): mixed => $set('min_selections', $state ? 1 : 0))
                    ->columnSpan(1),

                // Hidden while the group is optional and saved all the same:
                // that is when it holds the 0 that makes it optional. A field
                // saved while hidden is validated while hidden too, so its
                // floor follows the toggle rather than being a flat 1.
                TextInput::make('min_selections')
                    ->label(__('panel.add_on_groups.min_selections'))
                    ->integer()
                    ->minValue(fn (Get $get): int => (bool) $get('is_required') ? 1 : 0)
                    ->maxValue(99)
                    ->default(0)
                    ->required()
                    ->visible(fn (Get $get): bool => (bool) $get('is_required'))
                    ->dehydratedWhenHidden()
                    ->dehydrateStateUsing(fn (mixed $state): int => (int) $state)
                    ->rule(fn (Get $get): Closure => self::minimumRule($get))
                    ->columnSpan(1),

                TextInput::make('max_selections')
                    ->label(__('panel.add_on_groups.max_selections'))
                    ->integer()
                    ->minValue(1)
                    ->maxValue(99)
                    ->placeholder(__('panel.add_on_groups.no_limit'))
                    ->dehydrateStateUsing(fn (mixed $state): ?int => blank($state) ? null : (int) $state)
                    ->rule(fn (Get $get): Closure => self::maximumRule($get))
                    ->columnSpan(1),
            ]);
    }

    /**
     * What a guest picks from, in the order they read it.
     *
     * A repeater bound to the relationship, so the options are written in the
     * same save as the group. Each field below is one table column and no
     * more: a translated name is two inputs, so it goes through
     * `TranslatedFields::textCell()` rather than a bare `text()`, which would
     * split across two cells and push every field after it one column right.
     */
    private static function optionsSection(Currency $currency): Section
    {
        return Section::make(__('panel.add_on_groups.options'))
            ->icon(Heroicon::OutlinedListBullet)
            ->compact()
            ->schema([
                Repeater::make('options')
                    ->relationship()
                    ->hiddenLabel()
                    ->table([
                        TableColumn::make(__('panel.add_on_groups.option'))->markAsRequired(),
                        TableColumn::make(__('panel.add_on_groups.price'))->width('8rem'),
                        TableColumn::make(__('panel.add_on_groups.max_quantity'))->width('6rem'),
                        TableColumn::make(__('panel.add_on_groups.tax_rate'))->width('6rem'),
                        TableColumn::make(__('panel.add_on_groups.is_preselected'))->width('6rem')->alignment(Alignment::Center),
                        TableColumn::make(__('panel.add_on_groups.is_available'))->width('6rem')->alignment(Alignment::Center),
                    ])
                    ->schema([
                        TranslatedFields::textCell('name', __('panel.add_on_groups.option'), maxLength: 64),

                        // Zero is a real price: a spice level costs nothing extra.
                        TextInput::make('price')
                            ->label(__('panel.add_on_groups.price'))
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(99999)
                            ->step(0.01)
                            ->default(0)
                            ->prefix($currency->symbol()),

                        TextInput::make('max_quantity')
                            ->label(__('panel.add_on_groups.max_quantity'))
                            ->required()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(99)
                            ->default(1)
                            ->rule(fn (Get $get): Closure => self::maxQuantityRule($get)),

                        PricingFields::taxRatePercentage(PricingFields::tenantTaxRateBasisPoints()),

                        Toggle::make('is_preselected')
                            ->label(__('panel.add_on_groups.is_preselected'))
                            ->default(false),

                        Toggle::make('is_available')
                            ->label(__('panel.add_on_groups.is_available'))
                            ->default(true),
                    ])
                    ->orderColumn('position')
                    // A group with nothing in it offers a guest nothing to pick.
                    ->minItems(1)
                    ->defaultItems(1)
                    ->addActionLabel(__('panel.add_on_groups.add_option'))
                    ->reorderable()
                    ->rule(fn (Get $get): Closure => self::defaultsRule($get))
                    ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => self::storeOption($data, $currency))
                    ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => self::storeOption($data, $currency))
                    ->mutateRelationshipDataBeforeFillUsing(fn (array $data): array => self::fillOption($data, $currency))
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Refuse a minimum the options could never add up to, however many of each a guest took.
     */
    private static function minimumRule(Get $get): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            $picks = collect((array) $get('options'))
                ->sum(static fn (mixed $option): int => is_array($option) ? max((int) ($option['max_quantity'] ?? 1), 1) : 0);

            if ((int) $value > $picks) {
                $fail(__('panel.add_on_groups.min_above_options'));
            }
        };
    }

    /**
     * Refuse a maximum below the minimum, and never below one.
     *
     * The database states this as a CHECK; this is the message an admin reads
     * instead of it. The other half of the old rule here — too many options set
     * as the default — moved to `defaultsRule()`, so it reads under the options
     * table rather than under this field.
     */
    private static function maximumRule(Get $get): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            if (blank($value)) {
                return;
            }

            if ((int) $value < max((int) $get('min_selections'), 1)) {
                $fail(__('panel.add_on_groups.max_below_min'));
            }
        };
    }

    /**
     * Refuse more options set as the default than a guest may pick.
     *
     * Attached to the repeater itself rather than to Max choices, so the
     * message lands under the options it is actually about.
     */
    private static function defaultsRule(Get $get): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            $max = $get('max_selections');

            if (blank($max)) {
                return;
            }

            $preselected = collect((array) $value)
                ->filter(static fn (mixed $option): bool => is_array($option) && (bool) ($option['is_preselected'] ?? false))
                ->count();

            if ($preselected > (int) $max) {
                $fail(__('panel.add_on_groups.preselected_above_max'));
            }
        };
    }

    /**
     * Refuse more of one option than the group's own maximum allows.
     *
     * A guest cannot take three of an option when the group limits the whole
     * pick to one — a required bread with "Up to 3" garlic naan would let a
     * guest "choose 1" and walk away with three.
     */
    private static function maxQuantityRule(Get $get): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            $max = $get('../../max_selections');

            if (filled($max) && (int) $value > (int) $max) {
                $fail(__('panel.add_on_groups.max_quantity_above_max'));
            }
        };
    }

    /**
     * Make a group from the item form's select, options and all, and hand back its key.
     *
     * What Filament's create action does, by hand, because the select names a
     * group rather than creating through a relationship: the group is stamped
     * with this tenant, and the repeater saves its options once it exists.
     *
     * @param  array<string, mixed>  $data
     */
    public static function createFromItemForm(array $data, Schema $schema): int
    {
        Gate::authorize('create', MenuAddOnGroup::class);

        $group = new MenuAddOnGroup($data);
        $group->forceFill(['tenant_id' => self::tenantKey() ?? throw new LogicException('An add-on group needs a tenant.')]);
        $group->save();

        $schema->model($group)->saveRelationships();

        // The select's options were memoized before the group existed, and it
        // could not label the group it has just been set to.
        Once::flush();

        return $group->getKey();
    }

    /**
     * This tenant's groups by name, each labelled with what it asks of a guest.
     *
     * @return array<int, string>
     */
    public static function groupOptions(): array
    {
        // once(): the item form's repeater asks every row's select.
        return once(fn (): array => MenuAddOnGroup::query()
            ->where('tenant_id', self::tenantKey())
            ->byName()
            ->get(['id', 'name', 'min_selections', 'max_selections'])
            ->mapWithKeys(fn (MenuAddOnGroup $group): array => [
                $group->getKey() => sprintf('%s — %s', $group->name, self::ruleSummary($group->min_selections, $group->max_selections)),
            ])
            ->all());
    }

    /**
     * A group's rule in a few words: "Required · Choose 1", "Optional · Up to 3".
     */
    public static function ruleSummary(int $min, ?int $max): string
    {
        $kind = $min >= 1 ? __('panel.add_on_groups.required') : __('panel.add_on_groups.optional');

        $rule = match (true) {
            $max !== null && $max === $min => __('panel.add_on_groups.rule_exactly', ['count' => $max]),
            $max !== null && $min === 0 => __('panel.add_on_groups.rule_up_to', ['count' => $max]),
            $max !== null => __('panel.add_on_groups.rule_between', ['min' => $min, 'max' => $max]),
            $min >= 1 => __('panel.add_on_groups.rule_at_least', ['count' => $min]),
            default => __('panel.add_on_groups.rule_any'),
        };

        return sprintf('%s · %s', (string) $kind, (string) $rule);
    }

    /**
     * Put every language back into the form when a group is edited.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillTranslations(array $data, MenuAddOnGroup $record): array
    {
        return $record->fillTranslationsInto($data, ...self::TRANSLATED);
    }

    /**
     * One option's typed price and rate as what gets stored.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function storeOption(array $data, Currency $currency): array
    {
        $data['price_minor_units'] = $currency->toMinorUnits($data['price'] ?? 0);

        $data['tax_rate_basis_points'] = blank($data['tax_rate_percentage'] ?? null)
            ? null
            : PricingFields::toBasisPoints($data['tax_rate_percentage']);

        unset($data['price'], $data['tax_rate_percentage']);

        return $data;
    }

    /**
     * One option's stored price and rate as what the form edits.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function fillOption(array $data, Currency $currency): array
    {
        $data['price'] = $currency->toMajorUnits((int) ($data['price_minor_units'] ?? 0));

        $data['tax_rate_percentage'] = blank($data['tax_rate_basis_points'] ?? null)
            ? null
            : PricingFields::toPercentage((int) $data['tax_rate_basis_points']);

        return $data;
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
