<?php

namespace App\Filament\Tenant\Resources\MenuAddOnGroups\Schemas;

use App\Enums\Currency;
use App\Filament\Schemas\PricingFields;
use App\Filament\Schemas\StockFields;
use App\Filament\Schemas\TranslatedFields;
use App\Models\MenuAddOnGroup;
use App\Models\MenuAddOnOption;
use App\Models\TaxCode;
use App\Models\Tenant;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Once;
use LogicException;

/**
 * An add-on group: what a guest reads above it, whether they must pick from it, and how many they may pick.
 *
 * Two answers on the group and no more: Required, and a Maximum picks (blank
 * for any number). A minimum and a pair of "how many" buttons were asked once
 * and taken out on the project owner's instruction — a required group means at
 * least one pick (.ai/rules/add-on-groups.md). An item linking the group may
 * cap its own Maximum picks tighter or looser (MenuItemForm), which is the one
 * thing a group does not settle for every item alike.
 *
 * Each option carries its own Max each — how many of that one option a guest
 * may take — always, not behind a switch: a group of one pick still shows it,
 * capped at one, because a hidden column that reappeared once the maximum
 * changed was harder to find than a column disabled at one.
 *
 * An option's **GST rate and HSN/SAC code are normally left blank**, and blank
 * is the right answer: an add-on is part of the item it is added to, a
 * composite supply taxed at that item's rate. They are there for the option
 * that is really a separate supply — a haircut offered beside a meal — and
 * `MenuAddOnOption::taxRate()` is what falls back to the item's.
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
            ->columns(2)
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
                // group is required as well. Blank is any number. An item
                // offering this group may cap its own picks differently
                // (MenuItemForm); this is only the group's own default.
                TextInput::make('max_picks')
                    ->label(__('panel.add_on_groups.max_picks'))
                    ->integer()
                    ->minValue(1)
                    ->maxValue(99)
                    ->default(1)
                    ->placeholder(__('panel.add_on_groups.no_limit'))
                    ->live(onBlur: true)
                    ->dehydrateStateUsing(fn (mixed $state): ?int => blank($state) ? null : (int) $state),
            ]);
    }

    /**
     * What a guest picks from, in the order they read it.
     *
     * A repeater bound to the relationship, so the options are written in the
     * same save as the group. Max each is always a column — there is always
     * exactly one cell per column, a translated name included, through
     * `TranslatedFields::textCell()` (.ai/rules/filament.md) — disabled and
     * forced to one while the group is a single pick, since taking one option
     * twice needs room for two.
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
                        TableColumn::make(__('panel.add_on_groups.price'))->width('9rem'),
                        TableColumn::make(__('panel.add_on_groups.tax_code'))->width('16rem'),
                        TableColumn::make(__('panel.add_on_groups.max_per_item'))->width('7rem'),
                        TableColumn::make(__('panel.stock.in_stock'))->width('8rem'),
                        TableColumn::make(__('panel.add_on_groups.is_default'))->width('7rem')->alignment(Alignment::Center),
                        TableColumn::make(__('panel.add_on_groups.is_available'))->width('7rem')->alignment(Alignment::Center),
                    ])
                    ->schema([
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

                        // One column rather than a rate and a code side by
                        // side: both are copied from whatever is picked here,
                        // so the two boxes they used to fill were read-only
                        // repetitions of this label. Blank is the ordinary
                        // answer and means "taxed with the item", which is what
                        // a composite supply is; a code is for the option that
                        // is really a separate supply.
                        Select::make('tax_code_id')
                            ->label(__('panel.add_on_groups.tax_code'))
                            ->placeholder(__('panel.add_on_groups.tax_rate_placeholder'))
                            ->options(fn (): array => PricingFields::taxCodeOptions())
                            ->searchable()
                            ->native(false),

                        TextInput::make('max_per_item')
                            ->label(__('panel.add_on_groups.max_per_item'))
                            ->required()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(99)
                            ->default(1)
                            ->disabled(fn (Get $get): bool => self::isOnePick($get, '../../'))
                            ->rule(fn (Get $get): Closure => self::maxPerItemRule($get)),

                        // The count and the count the modal opened with, grouped
                        // so the hidden half does not draw a cell of its own.
                        Group::make([
                            StockFields::quantity(),
                            StockFields::loaded(),
                        ]),

                        Toggle::make('is_default')
                            ->label(__('panel.add_on_groups.is_default'))
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
                    ->mutateRelationshipDataBeforeCreateUsing(fn (array $data, Get $get): array => StockFields::storeNew(self::storeOption($data, $currency, self::isOnePick($get))))
                    ->mutateRelationshipDataBeforeSaveUsing(fn (array $data, Get $get, MenuAddOnOption $record): array => self::storeExistingOption($data, $record, $currency, self::isOnePick($get)))
                    ->mutateRelationshipDataBeforeFillUsing(fn (array $data): array => StockFields::fill(self::fillOption($data, $currency)))
                    ->columnSpanFull(),
            ]);
    }

    /**
     * The Maximum typed, or null for no limit.
     */
    private static function maximum(Get $get, string $path = ''): ?int
    {
        $maximum = $get($path.'max_picks');

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
     * levels up. Skipped for a single pick: the field is disabled there and
     * storeOption() forces it to one whatever was typed before it was disabled.
     */
    private static function maxPerItemRule(Get $get): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            if (self::isOnePick($get, '../../')) {
                return;
            }

            $maximum = self::maximum($get, '../../');

            if ($maximum !== null && (int) $value > $maximum) {
                $fail(__('panel.add_on_groups.max_per_item_above_max'));
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
     * This tenant's groups, each with how many of its own options are ticked as the default.
     *
     * The defaults count is what an item's own Maximum on this item is checked
     * against: an item cannot cap the group below how many options it defaults
     * to ticking.
     *
     * @return Collection<int, MenuAddOnGroup>
     */
    public static function groupsForItemForm(): Collection
    {
        // once(): the item form's repeater asks every row's select, and its
        // maximum's placeholder and validation rule, while it renders.
        return once(fn (): Collection => MenuAddOnGroup::query()
            ->where('tenant_id', self::tenantKey())
            ->byName()
            ->withCount(['options as defaults_count' => fn (Builder $query): Builder => $query->where('is_default', true)])
            ->get(['id', 'name', 'is_required', 'max_picks'])
            ->keyBy(fn (MenuAddOnGroup $group): int => $group->getKey()));
    }

    /**
     * One of this tenant's groups, from the same cached lookup groupOptions() and the item form's Maximum column read.
     */
    public static function groupForItemForm(int $groupId): ?MenuAddOnGroup
    {
        return self::groupsForItemForm()->get($groupId);
    }

    /**
     * How many of a group's options are ticked as the default, from that same lookup.
     *
     * `defaults_count` is a `withCount()` aggregate rather than a column, so it is
     * read as an attribute: nothing carries it but the query above. A group this
     * tenant does not have counts as none, and the select refuses it anyway.
     */
    public static function defaultsCountOf(int $groupId): int
    {
        return (int) self::groupForItemForm($groupId)?->getAttribute('defaults_count');
    }

    /**
     * This tenant's groups by name, each labelled with what it asks of a guest.
     *
     * @return array<int, string>
     */
    public static function groupOptions(): array
    {
        return self::groupsForItemForm()
            ->mapWithKeys(fn (MenuAddOnGroup $group): array => [
                $group->getKey() => sprintf('%s — %s', $group->name, self::ruleSummary($group->is_required, $group->max_picks)),
            ])
            ->all();
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
     * One option's typed price as what gets stored, and one of each while the group is a single pick.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function storeOption(array $data, Currency $currency, bool $isOnePick): array
    {
        // Typed in rupees, stored in paise, under the same name — converted
        // in place, so there is nothing left to unset. See PricingFields::store().
        $data['price'] = blank($data['price'] ?? null) ? 0 : $currency->toMinorUnits($data['price']);

        // Whatever the picked code carries, copied onto the option and then
        // let go. Blank stays null, which means "taxed with the item".
        $taxCode = blank($data['tax_code_id'] ?? null) ? null : TaxCode::query()->find((int) $data['tax_code_id']);

        $data['tax_rate'] = $taxCode?->tax_rate;
        $data['hsn_sac_code'] = $taxCode?->code;

        unset($data['tax_code_id']);

        if ($isOnePick) {
            $data['max_per_item'] = 1;
        }

        return $data;
    }

    /**
     * A saved option's row as it is stored, with its count set under the lock only if this modal changed it.
     *
     * The row itself is saved after this returns, without the count, so it cannot
     * put back a count an order has taken from while the modal was open.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function storeExistingOption(array $data, MenuAddOnOption $record, Currency $currency, bool $isOnePick): array
    {
        [$data, $typed, $loaded] = StockFields::pull(self::storeOption($data, $currency, $isOnePick));

        StockFields::applyIfChanged($record, $typed, $loaded);

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
        $minorUnits = (int) ($data['price'] ?? 0);

        $data['price'] = $minorUnits === 0 ? null : $currency->toMajorUnits($minorUnits);

        // Worked out from what the option stored, because nothing keeps the id.
        $data['tax_code_id'] = PricingFields::taxCodeIdFor(
            $data['hsn_sac_code'] ?? null,
            $data['tax_rate'] ?? null,
        );

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
