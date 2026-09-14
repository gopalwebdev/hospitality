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
use Filament\Forms\Components\ToggleButtons;
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
 * An add-on group: what a guest reads above it, the rule their picks follow, and the options they pick from.
 *
 * The form asks what Toast, Square and DoorDash ask, in their order, and each
 * question only once it applies: is it required; can a guest pick only one or
 * more than one; and, for more than one, the minimum, the maximum, and whether
 * the same option may be taken twice. The two questions are form state. What
 * is stored is what they mean — `min_selections`, `max_selections` and
 * `allows_quantities` — and the numbers are worked out from the answers on the
 * way out, so a box hidden by one answer can never leak a value typed under
 * another (.ai/rules/add-on-groups.md).
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

    /** "Is it required?" — no. */
    public const string OPTIONAL = 'optional';

    /** "Is it required?" — yes. */
    public const string REQUIRED = 'required';

    /** "How many can a guest pick?" — radio buttons for the guest. */
    public const string ONLY_ONE = 'only_one';

    /** "How many can a guest pick?" — checkboxes for the guest. */
    public const string MORE_THAN_ONE = 'more_than_one';

    /**
     * The group's questions on top, its options table below — stacked, so the
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
            ->columns(6)
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

                ToggleButtons::make('requirement')
                    ->label(__('panel.add_on_groups.requirement'))
                    ->options([
                        self::OPTIONAL => __('panel.add_on_groups.optional'),
                        self::REQUIRED => __('panel.add_on_groups.required'),
                    ])
                    ->inline()
                    ->grouped()
                    ->default(self::OPTIONAL)
                    ->live()
                    ->dehydrated(false)
                    ->afterStateHydrated(fn (ToggleButtons $component, Get $get): ToggleButtons => $component->state(self::requirementOf($get)))
                    ->columnSpan(3),

                ToggleButtons::make('selection')
                    ->label(__('panel.add_on_groups.selection'))
                    ->options([
                        self::ONLY_ONE => __('panel.add_on_groups.only_one'),
                        self::MORE_THAN_ONE => __('panel.add_on_groups.more_than_one'),
                    ])
                    ->inline()
                    ->grouped()
                    ->default(self::ONLY_ONE)
                    ->live()
                    ->dehydrated(false)
                    ->afterStateHydrated(fn (ToggleButtons $component, Get $get): ToggleButtons => $component->state(self::selectionOf($get)))
                    // A maximum of one is "only one"; switching to more than one
                    // starts from no limit rather than from a refusal.
                    ->afterStateUpdated(fn (?string $state, Get $get, Set $set): mixed => $state === self::MORE_THAN_ONE && (int) $get('max_selections') === 1
                        ? $set('max_selections', null)
                        : null)
                    ->columnSpan(3),

                // Only asked for a required group of more than one: "only one"
                // is a minimum of one when required and none when optional.
                // Saved while hidden, so its floor follows the same answers as
                // its visibility (.ai/rules/filament.md).
                TextInput::make('min_selections')
                    ->label(__('panel.add_on_groups.min_selections'))
                    ->integer()
                    ->minValue(fn (Get $get): int => self::asksForMinimum($get) ? 1 : 0)
                    ->maxValue(99)
                    ->default(0)
                    ->required()
                    ->visible(fn (Get $get): bool => self::asksForMinimum($get))
                    ->dehydratedWhenHidden()
                    ->dehydrateStateUsing(fn (Get $get): int => self::minimum($get))
                    ->rule(fn (Get $get): Closure => self::minimumRule($get))
                    ->columnSpan(2),

                TextInput::make('max_selections')
                    ->label(__('panel.add_on_groups.max_selections'))
                    ->integer()
                    ->minValue(fn (Get $get): int => self::allowsMoreThanOne($get) ? 2 : 1)
                    ->maxValue(99)
                    ->default(1)
                    ->placeholder(__('panel.add_on_groups.no_limit'))
                    ->validationMessages(['min' => __('panel.add_on_groups.max_below_two')])
                    ->visible(fn (Get $get): bool => self::allowsMoreThanOne($get))
                    ->dehydratedWhenHidden()
                    ->dehydrateStateUsing(fn (Get $get): ?int => self::maximum($get))
                    ->rule(fn (Get $get): Closure => self::maximumRule($get))
                    ->columnSpan(2),

                Toggle::make('allows_quantities')
                    ->label(__('panel.add_on_groups.allows_quantities'))
                    ->inline(false)
                    ->default(false)
                    ->live()
                    ->visible(fn (Get $get): bool => self::allowsMoreThanOne($get))
                    ->dehydratedWhenHidden()
                    ->dehydrateStateUsing(fn (Get $get): bool => self::allowsQuantities($get))
                    ->columnSpan(2),
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
     * "Required" when the stored minimum asks for a pick, and "Optional" otherwise — a new group included.
     */
    private static function requirementOf(Get $get): string
    {
        return (int) $get('min_selections') >= 1 ? self::REQUIRED : self::OPTIONAL;
    }

    /**
     * "Only one" for a new group or a stored maximum of one, and "More than one" otherwise.
     *
     * A new group's minimum may not have its default yet when this runs, which
     * is what tells it apart from a stored group with no limit.
     */
    private static function selectionOf(Get $get): string
    {
        if ($get('min_selections') === null) {
            return self::ONLY_ONE;
        }

        return (int) $get('max_selections') === 1 ? self::ONLY_ONE : self::MORE_THAN_ONE;
    }

    private static function isRequired(Get $get, string $path = ''): bool
    {
        return $get($path.'requirement') === self::REQUIRED;
    }

    private static function allowsMoreThanOne(Get $get, string $path = ''): bool
    {
        return $get($path.'selection') === self::MORE_THAN_ONE;
    }

    /**
     * Whether the Minimum box is asked at all: only for a required group of more than one.
     */
    private static function asksForMinimum(Get $get): bool
    {
        return self::isRequired($get) && self::allowsMoreThanOne($get);
    }

    /**
     * The minimum the answers mean: none when optional, one for a required "only one", else what was typed.
     */
    private static function minimum(Get $get, string $path = ''): int
    {
        if (! self::isRequired($get, $path)) {
            return 0;
        }

        return self::allowsMoreThanOne($get, $path) ? max((int) $get($path.'min_selections'), 1) : 1;
    }

    /**
     * The maximum the answers mean: one for "only one", else what was typed, and null for no limit.
     */
    private static function maximum(Get $get, string $path = ''): ?int
    {
        if (! self::allowsMoreThanOne($get, $path)) {
            return 1;
        }

        $maximum = $get($path.'max_selections');

        return blank($maximum) ? null : (int) $maximum;
    }

    /**
     * Whether one option may be taken more than once — only ever for a group of more than one.
     */
    private static function allowsQuantities(Get $get, string $path = ''): bool
    {
        return self::allowsMoreThanOne($get, $path) && (bool) $get($path.'allows_quantities');
    }

    /**
     * Refuse a minimum the options could never add up to, however many of each a guest may take.
     */
    private static function minimumRule(Get $get): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            $countsQuantities = self::allowsQuantities($get);

            $picks = collect((array) $get('options'))
                ->sum(static fn (mixed $option): int => match (true) {
                    ! is_array($option) => 0,
                    $countsQuantities => max((int) ($option['max_quantity'] ?? 1), 1),
                    default => 1,
                });

            if (self::minimum($get) > $picks) {
                $fail(__('panel.add_on_groups.min_above_options'));
            }
        };
    }

    /**
     * Refuse a maximum below the minimum.
     *
     * The database states this as a CHECK; this is the message an admin reads
     * instead of it. "Only one" is never refused here: its maximum is one.
     */
    private static function maximumRule(Get $get): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            $maximum = self::maximum($get);

            if (self::allowsMoreThanOne($get) && $maximum !== null && $maximum < self::minimum($get)) {
                $fail(__('panel.add_on_groups.max_below_min'));
            }
        };
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
