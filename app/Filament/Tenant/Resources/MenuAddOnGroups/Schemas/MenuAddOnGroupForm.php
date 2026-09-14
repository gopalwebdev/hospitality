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
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Once;
use LogicException;

/**
 * An add-on group: what a guest reads above it, whether they must pick from it, how many they may pick, and the options.
 *
 * Three answers and no more: Required, a Maximum (blank for any number), and
 * whether one option may be taken twice. A minimum and a pair of "how many"
 * buttons were asked once and taken out on the project owner's instruction —
 * a required group means at least one pick (.ai/rules/add-on-groups.md).
 *
 * An option has a price and no tax rate: an add-on is part of the item it is
 * added to, taxed at that item's rate.
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
     * The group's answers on top, its options table below — stacked, so the
     * table gets the modal's full width.
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
     * The group's name and the rule a guest's picks have to meet.
     */
    private static function groupSection(): Section
    {
        return Section::make(__('panel.add_on_groups.section'))
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->compact()
            ->columns(3)
            ->schema([
                ...array_map(
                    static fn (TextInput $field): TextInput => $field->columnSpanFull(),
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

                Toggle::make('is_required')
                    ->label(__('panel.add_on_groups.required'))
                    ->inline(false)
                    ->default(false),

                // One is a single pick, which a guest reads as radios when the
                // group is required as well. Blank is any number.
                TextInput::make('max_selections')
                    ->label(__('panel.add_on_groups.max_selections'))
                    ->integer()
                    ->minValue(1)
                    ->maxValue(99)
                    ->default(1)
                    ->placeholder(__('panel.add_on_groups.no_limit'))
                    ->live(onBlur: true)
                    ->dehydrateStateUsing(fn (mixed $state): ?int => blank($state) ? null : (int) $state),

                // Taking one option twice needs room for two picks, so it is not
                // offered while a guest may pick only one, and is saved off then.
                Toggle::make('allows_quantities')
                    ->label(__('panel.add_on_groups.allows_quantities'))
                    ->inline(false)
                    ->default(false)
                    ->live()
                    ->visible(fn (Get $get): bool => ! self::isOnePick($get))
                    ->dehydratedWhenHidden()
                    ->dehydrateStateUsing(fn (Get $get): bool => self::allowsQuantities($get)),
            ]);
    }

    /**
     * What a guest picks from, in the order they read it.
     *
     * A repeater bound to the relationship, so the options are written in the
     * same save as the group. Max qty is a column only while the same option may
     * be taken twice, and the row's fields follow the same answer, so there is
     * always exactly one cell per column — a translated name included, through
     * `TranslatedFields::textCell()` (.ai/rules/filament.md).
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
                    ->table(fn (Get $get): array => self::withoutNulls([
                        TableColumn::make(__('panel.add_on_groups.option'))->markAsRequired(),
                        TableColumn::make(__('panel.add_on_groups.price'))->width('9rem'),
                        self::allowsQuantities($get)
                            ? TableColumn::make(__('panel.add_on_groups.max_quantity'))->width('7rem')
                            : null,
                        TableColumn::make(__('panel.add_on_groups.is_default'))->width('7rem')->alignment(Alignment::Center),
                        TableColumn::make(__('panel.add_on_groups.is_available'))->width('7rem')->alignment(Alignment::Center),
                    ]))
                    ->schema(fn (Get $get): array => self::withoutNulls([
                        TranslatedFields::textCell('name', __('panel.add_on_groups.option'), maxLength: 64),

                        // Blank is free: a spice level costs nothing extra.
                        TextInput::make('price')
                            ->label(__('panel.add_on_groups.price'))
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(99999)
                            ->step(0.01)
                            ->prefix('+ '.$currency->symbol())
                            ->placeholder(__('panel.add_on_groups.free')),

                        self::allowsQuantities($get)
                            ? TextInput::make('max_quantity')
                                ->label(__('panel.add_on_groups.max_quantity'))
                                ->required()
                                ->integer()
                                ->minValue(1)
                                ->maxValue(99)
                                ->default(1)
                                ->rule(fn (Get $get): Closure => self::maxQuantityRule($get))
                            : null,

                        Toggle::make('is_default')
                            ->label(__('panel.add_on_groups.is_default'))
                            ->default(false),

                        Toggle::make('is_available')
                            ->label(__('panel.add_on_groups.is_available'))
                            ->default(true),
                    ]))
                    ->orderColumn('position')
                    // A group with nothing in it offers a guest nothing to pick.
                    ->minItems(1)
                    ->defaultItems(1)
                    ->addActionLabel(__('panel.add_on_groups.add_option'))
                    ->reorderable()
                    ->rule(fn (Get $get): Closure => self::defaultsRule($get))
                    ->mutateRelationshipDataBeforeCreateUsing(fn (array $data, Get $get): array => self::storeOption($data, $currency, self::allowsQuantities($get)))
                    ->mutateRelationshipDataBeforeSaveUsing(fn (array $data, Get $get): array => self::storeOption($data, $currency, self::allowsQuantities($get)))
                    ->mutateRelationshipDataBeforeFillUsing(fn (array $data): array => self::fillOption($data, $currency))
                    ->columnSpanFull(),
            ]);
    }

    /**
     * The Maximum typed, or null for no limit.
     */
    private static function maximum(Get $get, string $path = ''): ?int
    {
        $maximum = $get($path.'max_selections');

        return blank($maximum) ? null : (int) $maximum;
    }

    /**
     * Whether a guest may pick only one option from the group.
     */
    private static function isOnePick(Get $get, string $path = ''): bool
    {
        return self::maximum($get, $path) === 1;
    }

    /**
     * Whether one option may be taken more than once — never while a guest picks only one.
     */
    private static function allowsQuantities(Get $get, string $path = ''): bool
    {
        return ! self::isOnePick($get, $path) && (bool) $get($path.'allows_quantities');
    }

    /**
     * Refuse more options set as the default than a guest may pick.
     *
     * Attached to the repeater itself, so the message lands under the options
     * it is about.
     */
    private static function defaultsRule(Get $get): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            $maximum = self::maximum($get);

            if ($maximum === null) {
                return;
            }

            $defaults = collect((array) $value)
                ->filter(static fn (mixed $option): bool => is_array($option) && (bool) ($option['is_default'] ?? false))
                ->count();

            if ($defaults > $maximum) {
                $fail(__('panel.add_on_groups.defaults_above_max'));
            }
        };
    }

    /**
     * Refuse more of one option than the group's own maximum allows.
     *
     * A guest who may pick two things in all cannot take three extra cheese. Read
     * from inside a row of the options table, so the group's answers are two
     * levels up.
     */
    private static function maxQuantityRule(Get $get): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            $maximum = self::maximum($get, '../../');

            if ($maximum !== null && (int) $value > $maximum) {
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
            ->get(['id', 'name', 'is_required', 'max_selections'])
            ->mapWithKeys(fn (MenuAddOnGroup $group): array => [
                $group->getKey() => sprintf('%s — %s', $group->name, self::ruleSummary($group->is_required, $group->max_selections)),
            ])
            ->all());
    }

    /**
     * A group's rule in a few words: "Required · Choose 1", "Optional · Up to 3".
     *
     * The same cases as ruleOf() in resources/js/lib/add-on-rules.ts, which words
     * them for a guest.
     */
    public static function ruleSummary(bool $required, ?int $max): string
    {
        $kind = $required ? __('panel.add_on_groups.required') : __('panel.add_on_groups.optional');

        $rule = match (true) {
            $required && $max === 1 => __('panel.add_on_groups.rule_exactly', ['count' => 1]),
            $max !== null => __('panel.add_on_groups.rule_up_to', ['count' => $max]),
            $required => __('panel.add_on_groups.rule_at_least', ['count' => 1]),
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
     * One option's typed price as what gets stored, and one of each while quantities are not allowed.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function storeOption(array $data, Currency $currency, bool $allowsQuantities): array
    {
        $data['price_minor_units'] = blank($data['price'] ?? null) ? 0 : $currency->toMinorUnits($data['price']);

        if (! $allowsQuantities) {
            $data['max_quantity'] = 1;
        }

        unset($data['price']);

        return $data;
    }

    /**
     * One option's stored price as what the form edits — blank when free, so the box reads "Free".
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function fillOption(array $data, Currency $currency): array
    {
        $minorUnits = (int) ($data['price_minor_units'] ?? 0);

        $data['price'] = $minorUnits === 0 ? null : $currency->toMajorUnits($minorUnits);

        return $data;
    }

    /**
     * A list of components with the ones an answer leaves out removed.
     *
     * @template TComponent of object
     *
     * @param  list<TComponent|null>  $components
     * @return list<TComponent>
     */
    private static function withoutNulls(array $components): array
    {
        return array_values(array_filter($components, static fn (?object $component): bool => $component !== null));
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
