<?php

namespace App\Filament\Platform\Resources\Roles\Schemas;

use App\Enums\PermissionGroup;
use App\Models\Permission;
use App\Models\Role;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Role')
                    ->icon(Heroicon::OutlinedIdentification)
                    ->schema([
                        // Role names are referenced as strings from code and from
                        // the seeder, so they follow one shape: lowercase, hyphenated.
                        // A built-in role's name is what code checks with
                        // hasRole(), so it is read-only here while everything
                        // it grants stays editable below. disabled() also stops
                        // the field being dehydrated, so a tampered request
                        // cannot rename one either.
                        TextInput::make('name')
                            ->required()
                            ->maxLength(64)
                            ->unique(ignoreRecord: true)
                            ->prefixIcon(Heroicon::OutlinedIdentification)
                            ->rule('regex:/^[a-z0-9]+(-[a-z0-9]+)*$/')
                            ->disabled(fn (?Role $record): bool => $record instanceof Role && $record->isBuiltIn())
                            ->helperText(fn (?Role $record): string => $record instanceof Role && $record->isBuiltIn()
                                ? 'This role is declared in code, so its name is fixed. What it grants is yours to change below.'
                                : 'Lowercase, hyphenated, and permanent: code refers to a role by this name.')
                            ->validationMessages([
                                'regex' => 'Use lowercase letters, numbers and hyphens only.',
                            ]),
                    ]),

                ...self::permissionSections(),
            ]);
    }

    /**
     * One section per category, each with its own multi-select.
     *
     * A flat list of every permission says nothing about what a role does, so
     * they are split by PermissionGroup and each select gets its own
     * "Select all", which the checkbox lists these replaced had.
     * A category with nothing in it is left out rather than shown empty.
     *
     * The lists are deliberately not bound with ->relationship(): Filament would
     * write the pivot directly, leaving Spatie's registrar holding the previous
     * set for the rest of the request. CreateRole and EditRole collect the ids
     * back with pullPermissionIds() and hand them to SetRolePermissions.
     *
     * @return list<Section>
     */
    private static function permissionSections(): array
    {
        $byGroup = self::permissionsByGroup();

        return array_values(array_filter(array_map(
            static function (PermissionGroup $group) use ($byGroup): ?Section {
                $options = $byGroup[$group->value] ?? [];

                if ($options === []) {
                    return null;
                }

                return Section::make($group->label())
                    ->description($group->description())
                    ->icon($group->icon())
                    ->collapsible()
                    ->schema([
                        Select::make(self::statePathFor($group))
                            ->label('Permissions')
                            ->multiple()
                            ->options($options)
                            ->searchable()
                            ->columnSpanFull()
                            ->hintAction(Action::make('selectAll')
                                ->label('Select all')
                                ->action(fn (Set $set): mixed => $set(self::statePathFor($group), array_keys($options)))),
                    ]);
            },
            PermissionGroup::ordered(),
        )));
    }

    /**
     * Every permission that exists, labelled and bucketed by category.
     *
     * @return array<string, array<int, string>>
     */
    private static function permissionsByGroup(): array
    {
        return Permission::query()
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Permission $permission): string => $permission->group()->value)
            ->map(fn (Collection $permissions): array => $permissions
                ->mapWithKeys(fn (Permission $permission): array => [$permission->getKey() => $permission->label()])
                ->all())
            ->all();
    }

    /**
     * The form state path a category's checkbox list writes to.
     *
     * Hyphens are not valid in a Livewire state path, so the group's backing
     * value is underscored on the way in.
     */
    public static function statePathFor(PermissionGroup $group): string
    {
        return 'permissions_'.str_replace('-', '_', $group->value);
    }

    /**
     * Take the chosen permission ids out of submitted form data.
     *
     * The ids arrive spread across one key per category, and nothing downstream
     * wants them that way: this collects them into the single list
     * SetRolePermissions takes, and removes the per-category keys so what is
     * left is only real columns on the role.
     *
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    public static function pullPermissionIds(array &$data): array
    {
        $ids = [];

        foreach (PermissionGroup::cases() as $group) {
            $key = self::statePathFor($group);

            if (! array_key_exists($key, $data)) {
                continue;
            }

            $ids = [...$ids, ...array_map(intval(...), (array) $data[$key])];

            unset($data[$key]);
        }

        return array_values(array_unique($ids));
    }

    /**
     * Spread a role's permission ids back across the per-category keys.
     *
     * @return array<string, list<int>>
     */
    public static function spreadPermissionIds(Permission ...$permissions): array
    {
        $spread = [];

        foreach (PermissionGroup::cases() as $group) {
            $spread[self::statePathFor($group)] = [];
        }

        foreach ($permissions as $permission) {
            $spread[self::statePathFor($permission->group())][] = $permission->getKey();
        }

        return $spread;
    }
}
