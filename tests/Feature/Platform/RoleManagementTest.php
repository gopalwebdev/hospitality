<?php

use App\Actions\Roles\SetRolePermissions;
use App\Enums\Permission as PermissionEnum;
use App\Enums\PermissionGroup;
use App\Enums\Role as RoleEnum;
use App\Filament\Platform\Resources\Roles\Pages\CreateRole;
use App\Filament\Platform\Resources\Roles\Pages\EditRole;
use App\Filament\Platform\Resources\Roles\Pages\ListRoles;
use App\Filament\Platform\Resources\Roles\RoleResource;
use App\Filament\Platform\Resources\Roles\Schemas\RoleForm;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Who may manage roles
|--------------------------------------------------------------------------
*/

it('lets the product team manage roles', function (): void {
    $user = User::factory()->admin()->create();
    $role = Role::factory()->create();

    expect($user->can('viewAny', Role::class))->toBeTrue()
        ->and($user->can('create', Role::class))->toBeTrue()
        ->and($user->can('update', $role))->toBeTrue()
        ->and($user->can('delete', $role))->toBeTrue();
});

it('refuses role management to every tenant role', function (RoleEnum $roleEnum): void {
    $user = User::factory()->create();
    $user->assignRole($roleEnum->value);
    $role = Role::factory()->create();

    expect($user->can('viewAny', Role::class))->toBeFalse()
        ->and($user->can('create', Role::class))->toBeFalse()
        ->and($user->can('update', $role))->toBeFalse()
        ->and($user->can('delete', $role))->toBeFalse();
})->with(RoleEnum::cases());

it('keeps a tenant owner off the roles page', function (): void {
    $user = User::factory()->create();
    $user->assignRole(RoleEnum::Owner->value);

    $this->actingAs($user)
        ->get('http://hospitality.test/dashboard/roles')
        ->assertForbidden();
});

it('serves the roles page to the product team', function (): void {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)
        ->get('http://hospitality.test/dashboard/roles')
        ->assertOk();
});

/*
|--------------------------------------------------------------------------
| Creating and editing custom roles
|--------------------------------------------------------------------------
*/

it('lists every role, built in and custom alike', function (): void {
    $custom = Role::factory()->create();
    enterProductTeamPanel();

    Livewire::test(ListRoles::class)
        ->assertCanSeeTableRecords(Role::query()->get())
        ->assertCanSeeTableRecords([$custom]);
});

it('creates a role with the permissions it is given', function (): void {
    enterProductTeamPanel();

    $menuView = Permission::query()->where('name', PermissionEnum::MenuView->value)->sole();
    $orderManage = Permission::query()->where('name', PermissionEnum::OrderManage->value)->sole();

    // Permissions are chosen per category, so the ids go to the category's
    // own field rather than to one flat list.
    Livewire::test(CreateRole::class)
        ->fillForm([
            'name' => 'kitchen-porter',
            RoleForm::statePathFor(PermissionGroup::Menu) => [$menuView->getKey()],
            RoleForm::statePathFor(PermissionGroup::Orders) => [$orderManage->getKey()],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $role = Role::query()->where('name', 'kitchen-porter')->sole();

    expect($role->permissions->pluck('name')->all())
        ->toEqualCanonicalizing([PermissionEnum::MenuView->value, PermissionEnum::OrderManage->value]);
});

it('updates a custom role', function (): void {
    $role = Role::factory()->create(['name' => 'kitchen-porter']);
    $menuView = Permission::query()->where('name', PermissionEnum::MenuView->value)->sole();
    enterProductTeamPanel();

    Livewire::test(EditRole::class, ['record' => $role->getKey()])
        ->fillForm([
            'name' => 'porter',
            RoleForm::statePathFor(PermissionGroup::Menu) => [$menuView->getKey()],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($role->refresh()->name)->toBe('porter')
        ->and($role->permissions->pluck('name')->all())->toBe([PermissionEnum::MenuView->value]);
});

it('deletes a custom role that nothing depends on', function (): void {
    $role = Role::factory()->create();
    enterProductTeamPanel();

    Livewire::test(EditRole::class, ['record' => $role->getKey()])
        ->callAction('delete');

    expect(Role::query()->whereKey($role->getKey())->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| A role in use may not be deleted
|--------------------------------------------------------------------------
|
| Deleting a role someone holds revokes what it granted them without saying
| so, and deleting one still carrying permissions loses the record of what it
| granted. Both have to be undone deliberately first.
|
*/

it('refuses to delete a role a user holds', function (): void {
    $role = Role::factory()->create();
    $user = User::factory()->create();
    $user->assignRole($role->name);

    enterProductTeamPanel();

    expect($role->isInUse())->toBeTrue()
        ->and($role->undeletableReason())->toContain('held by a user')
        ->and(RoleResource::canDelete($role))->toBeFalse();

    Livewire::test(ListRoles::class)
        ->assertTableActionHidden('delete', $role);

    expect(Role::query()->whereKey($role->getKey())->exists())->toBeTrue();
});

it('refuses to delete a role that still holds permissions', function (): void {
    $role = Role::factory()->create();
    $role->givePermissionTo(PermissionEnum::MenuView->value);

    enterProductTeamPanel();

    expect($role->isInUse())->toBeTrue()
        ->and($role->undeletableReason())->toContain('still holds permissions')
        ->and(RoleResource::canDelete($role))->toBeFalse();

    Livewire::test(ListRoles::class)
        ->assertTableActionHidden('delete', $role);
});

it('refuses to delete a role in use from anywhere', function (): void {
    $held = Role::factory()->create();
    User::factory()->create()->assignRole($held->name);

    expect(fn () => $held->delete())
        ->toThrow(LogicException::class, 'A role held by a user may not be deleted.');

    $stocked = Role::factory()->create();
    $stocked->givePermissionTo(PermissionEnum::MenuView->value);

    expect(fn () => $stocked->delete())
        ->toThrow(LogicException::class, 'A role holding permissions may not be deleted.');

    expect(Role::query()->whereKey($held->getKey())->exists())->toBeTrue()
        ->and(Role::query()->whereKey($stocked->getKey())->exists())->toBeTrue();
});

it('deletes a role once nothing depends on it any more', function (): void {
    $role = Role::factory()->create();
    $role->givePermissionTo(PermissionEnum::MenuView->value);
    $user = User::factory()->create();
    $user->assignRole($role->name);

    enterProductTeamPanel();

    expect(RoleResource::canDelete($role))->toBeFalse();

    // Take the person off it, then empty it, and it becomes deletable.
    $user->removeRole($role->name);
    $role->syncPermissions([]);

    expect($role->refresh()->undeletableReason())->toBeNull()
        ->and(RoleResource::canDelete($role))->toBeTrue();

    Livewire::test(EditRole::class, ['record' => $role->getKey()])
        ->callAction('delete');

    expect(Role::query()->whereKey($role->getKey())->exists())->toBeFalse();
});

it('says on the table why a role cannot be deleted', function (): void {
    $role = Role::factory()->create();
    $role->givePermissionTo(PermissionEnum::MenuView->value);

    enterProductTeamPanel();

    Livewire::test(ListRoles::class)
        ->assertTableColumnStateSet('is_deletable', false, $role);
});

/*
|--------------------------------------------------------------------------
| Permissions are synced through Spatie, not through the pivot
|--------------------------------------------------------------------------
*/

it('syncs a role\'s permissions so a check answers from the new set immediately', function (): void {
    $role = Role::factory()->create();
    $user = User::factory()->create();
    $user->assignRole($role->name);

    $menuManage = Permission::query()->where('name', PermissionEnum::MenuManage->value)->sole();

    enterProductTeamPanel();

    // Warm the registrar cache with the old set. Writing the pivot directly —
    // which a Filament ->relationship() checkbox list does — would leave this
    // answer standing for the rest of the request.
    expect($user->can(PermissionEnum::MenuManage->value))->toBeFalse();

    Livewire::test(EditRole::class, ['record' => $role->getKey()])
        ->fillForm([RoleForm::statePathFor(PermissionGroup::Menu) => [$menuManage->getKey()]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->fresh()->can(PermissionEnum::MenuManage->value))->toBeTrue();
});

it('sets a built-in role\'s permissions like any other', function (): void {
    $role = Role::findByName(RoleEnum::Staff->value);
    $menuView = Permission::query()->where('name', PermissionEnum::MenuView->value)->sole();

    app(SetRolePermissions::class)($role, [$menuView->getKey()]);

    expect($role->refresh()->permissions->pluck('name')->all())
        ->toBe([PermissionEnum::MenuView->value]);
});

/*
|--------------------------------------------------------------------------
| Permissions are chosen by category
|--------------------------------------------------------------------------
|
| A flat list of `subject.ability` strings says nothing about what a role
| does, so the form splits them into PermissionGroup sections, each with its
| own select-all.
|
*/

it('offers a permissions select for every category that has permissions', function (PermissionGroup $group): void {
    enterProductTeamPanel();

    $expected = Permission::query()->get()
        ->filter(fn (Permission $permission): bool => $permission->group() === $group);

    $page = Livewire::test(CreateRole::class);

    if ($expected->isEmpty()) {
        $page->assertFormFieldDoesNotExist(RoleForm::statePathFor($group));

        return;
    }

    // The list offers exactly that category's permissions, no more: options
    // are ordered by name, so compare as sets rather than by position.
    $page->assertFormFieldExists(
        RoleForm::statePathFor($group),
        function (Select $field) use ($expected): bool {
            $offered = array_keys($field->getOptions());
            sort($offered);

            $wanted = $expected->pluck('id')->all();
            sort($wanted);

            return $offered === $wanted;
        },
    );
})->with(PermissionGroup::ordered());

it('leaves out a category with nothing in it', function (): void {
    enterProductTeamPanel();

    // Nothing is declared under Other until a permission is added from the
    // panel, so the section has no reason to be rendered.
    expect(Permission::query()->get()->filter(
        fn (Permission $permission): bool => $permission->group() === PermissionGroup::Other,
    ))->toBeEmpty();

    Livewire::test(CreateRole::class)
        ->assertFormFieldDoesNotExist(RoleForm::statePathFor(PermissionGroup::Other));
});

it('files a permission added from the panel under Other, and then offers it', function (): void {
    $custom = Permission::factory()->create(['name' => 'kitchen.expedite']);

    enterProductTeamPanel();

    expect($custom->group())->toBe(PermissionGroup::Other);

    Livewire::test(CreateRole::class)
        ->assertFormFieldExists(
            RoleForm::statePathFor(PermissionGroup::Other),
            fn (Select $field): bool => array_key_exists((string) $custom->getKey(), $field->getOptions()),
        );
});

it('assigns permissions picked from several categories at once', function (): void {
    enterProductTeamPanel();

    $menu = Permission::query()->whereIn('name', [
        PermissionEnum::MenuView->value,
        PermissionEnum::MenuManage->value,
    ])->pluck('id')->all();

    $orders = Permission::query()->whereIn('name', [
        PermissionEnum::OrderViewAny->value,
        PermissionEnum::OrderManage->value,
    ])->pluck('id')->all();

    Livewire::test(CreateRole::class)
        ->fillForm([
            'name' => 'expediter',
            RoleForm::statePathFor(PermissionGroup::Menu) => $menu,
            RoleForm::statePathFor(PermissionGroup::Orders) => $orders,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Role::query()->where('name', 'expediter')->sole()->permissions->pluck('name')->all())
        ->toEqualCanonicalizing([
            PermissionEnum::MenuView->value,
            PermissionEnum::MenuManage->value,
            PermissionEnum::OrderViewAny->value,
            PermissionEnum::OrderManage->value,
        ]);
});

it('fills the edit form with each permission in its own category', function (): void {
    $role = Role::factory()->create();
    $role->syncPermissions([
        PermissionEnum::MenuView->value,
        PermissionEnum::OrderManage->value,
        PermissionEnum::RoleManage->value,
    ]);

    enterProductTeamPanel();

    $menuView = Permission::query()->where('name', PermissionEnum::MenuView->value)->sole();
    $orderManage = Permission::query()->where('name', PermissionEnum::OrderManage->value)->sole();
    $roleManage = Permission::query()->where('name', PermissionEnum::RoleManage->value)->sole();

    Livewire::test(EditRole::class, ['record' => $role->getKey()])
        ->assertFormSet([
            RoleForm::statePathFor(PermissionGroup::Menu) => [$menuView->getKey()],
            RoleForm::statePathFor(PermissionGroup::Orders) => [$orderManage->getKey()],
            RoleForm::statePathFor(PermissionGroup::ProductTeam) => [$roleManage->getKey()],
            RoleForm::statePathFor(PermissionGroup::People) => [],
        ]);
});

it('clears a category when everything in it is unticked', function (): void {
    $role = Role::factory()->create();
    $role->syncPermissions([PermissionEnum::MenuView->value, PermissionEnum::OrderManage->value]);

    enterProductTeamPanel();

    Livewire::test(EditRole::class, ['record' => $role->getKey()])
        ->fillForm([RoleForm::statePathFor(PermissionGroup::Menu) => []])
        ->call('save')
        ->assertHasNoFormErrors();

    // Only Menu was emptied; Orders was never touched and stays.
    expect($role->refresh()->permissions->pluck('name')->all())
        ->toBe([PermissionEnum::OrderManage->value]);
});

/*
|--------------------------------------------------------------------------
| The roles the application ships with
|--------------------------------------------------------------------------
*/

it('ships exactly the roles a tenant needs', function (): void {
    // Four kinds of account in the application: the product team, who are the
    // is_admin column rather than a role, and these three.
    expect(RoleEnum::values())->toBe(['owner', 'staff', 'guest'])
        ->and(Role::query()->orderBy('name')->pluck('name')->all())
        ->toBe(['guest', 'owner', 'staff']);
});

it('seeds a guest role that reads the menu and orders for itself', function (): void {
    $guest = Role::findByName(RoleEnum::Guest->value);

    expect($guest->isBuiltIn())->toBeTrue()
        ->and($guest->permissions->pluck('name')->all())->toEqualCanonicalizing([
            PermissionEnum::MenuView->value,
            // The tiles a guest lands on are read with the menu they lead to.
            PermissionEnum::StorefrontView->value,
            PermissionEnum::OrderCreate->value,
            PermissionEnum::OrderViewOwn->value,
        ]);
});

it('seeds staff who work orders but do not change the menu', function (): void {
    $staff = Role::findByName(RoleEnum::Staff->value);

    expect($staff->permissions->pluck('name')->all())->toEqualCanonicalizing([
        PermissionEnum::MenuView->value,
        PermissionEnum::StorefrontView->value,
        PermissionEnum::OrderViewAny->value,
        PermissionEnum::OrderManage->value,
    ])
        ->and($staff->hasPermissionTo(PermissionEnum::MenuManage->value))->toBeFalse()
        // Reading the home screen is not arranging it.
        ->and($staff->hasPermissionTo(PermissionEnum::StorefrontManage->value))->toBeFalse()
        ->and($staff->hasPermissionTo(PermissionEnum::UserManage->value))->toBeFalse();
});

it('gives a tenant owner everything but the product team\'s own powers', function (): void {
    $owner = Role::findByName(RoleEnum::Owner->value);

    expect($owner->permissions->pluck('name')->all())
        ->toEqualCanonicalizing(array_diff(PermissionEnum::values(), PermissionEnum::productTeamOnlyValues()));
});

it('offers the guest role to tenants', function (): void {
    expect(Role::query()->assignableWithinTenant()->pluck('name')->all())
        ->toContain(RoleEnum::Guest->value);
});

it('requires a role name in the shape code refers to it by', function (?string $name): void {
    enterProductTeamPanel();

    Livewire::test(CreateRole::class)
        ->fillForm(['name' => $name])
        ->call('create')
        ->assertHasFormErrors(['name']);
})->with([
    'missing' => null,
    'uppercase' => 'Kitchen',
    'spaced' => 'kitchen porter',
    'underscored' => 'kitchen_porter',
]);

it('refuses a role name that is already taken', function (): void {
    enterProductTeamPanel();

    Livewire::test(CreateRole::class)
        ->fillForm(['name' => RoleEnum::Staff->value])
        ->call('create')
        ->assertHasFormErrors(['name' => 'unique']);
});

/*
|--------------------------------------------------------------------------
| The list page costs the same whatever is on it
|--------------------------------------------------------------------------
*/

it('does not spend a query per row working out what may be deleted', function (): void {
    // Whether a role may be deleted depends on how many people hold it and how
    // many permissions it grants — both of which the table already loads with
    // withCount(). Asking the model again per row cost 57 extra queries here
    // before Role::holderCount() started preferring the loaded value.
    Role::factory()->count(30)->create()->each(function (Role $role): void {
        $role->givePermissionTo(PermissionEnum::MenuView->value);
        User::factory()->create()->assignRole($role->name);
    });

    enterProductTeamPanel();

    DB::enableQueryLog();

    Livewire::test(ListRoles::class)
        ->set('tableRecordsPerPage', 50)
        ->assertOk();

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Generous, and the point is that it does not grow with the row count:
    // a per-row lookup over 33 roles would be an order of magnitude more.
    expect($queries)->toBeLessThan(15);
});

/*
|--------------------------------------------------------------------------
| A built-in role's name is fixed; what it grants is not
|--------------------------------------------------------------------------
|
| Code refers to a role by name, so renaming or deleting one silently revokes
| access. What it grants is the product team's to change whenever they like,
| which is the whole point of the roles page.
|
*/

it('lets the product team edit a built-in role but never delete it', function (RoleEnum $roleEnum): void {
    enterProductTeamPanel();

    $role = Role::findByName($roleEnum->value);

    expect($role->isBuiltIn())->toBeTrue()
        ->and(RoleResource::canEdit($role))->toBeTrue()
        ->and(RoleResource::canDelete($role))->toBeFalse();
})->with(RoleEnum::cases());

it('changes what a built-in role grants, and keeps the change', function (): void {
    $role = Role::findByName(RoleEnum::Staff->value);
    $menuManage = Permission::query()->where('name', PermissionEnum::MenuManage->value)->sole();
    $menuView = Permission::query()->where('name', PermissionEnum::MenuView->value)->sole();

    enterProductTeamPanel();

    Livewire::test(EditRole::class, ['record' => $role->getKey()])
        ->fillForm([RoleForm::statePathFor(PermissionGroup::Menu) => [$menuView->getKey(), $menuManage->getKey()]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($role->refresh()->hasPermissionTo(PermissionEnum::MenuManage->value))->toBeTrue();

    // Re-seeding must not undo it: the enum is a starting point, and after the
    // first run what a role grants belongs to the product team.
    $this->seed(RolesAndPermissionsSeeder::class);

    expect($role->refresh()->hasPermissionTo(PermissionEnum::MenuManage->value))->toBeTrue();
});

it('locks the name of a built-in role in the form', function (): void {
    $role = Role::findByName(RoleEnum::Owner->value);

    enterProductTeamPanel();

    Livewire::test(EditRole::class, ['record' => $role->getKey()])
        ->assertFormFieldDisabled('name');
});

it('leaves the name of a custom role editable', function (): void {
    $role = Role::factory()->create();

    enterProductTeamPanel();

    Livewire::test(EditRole::class, ['record' => $role->getKey()])
        ->assertFormFieldEnabled('name');
});

it('allows editing and deleting a custom role', function (): void {
    enterProductTeamPanel();

    $role = Role::factory()->create();

    expect($role->isBuiltIn())->toBeFalse()
        ->and(RoleResource::canEdit($role))->toBeTrue()
        ->and(RoleResource::canDelete($role))->toBeTrue();
});

it('offers edit but not delete against a built-in role', function (RoleEnum $roleEnum): void {
    enterProductTeamPanel();

    $role = Role::findByName($roleEnum->value);

    // Filament authorises a record action against the policy, which
    // Gate::before answers for an admin, so without the explicit check on
    // the resource the delete button would render and then land on a 403.
    Livewire::test(ListRoles::class)
        ->assertTableActionVisible('edit', $role)
        ->assertTableActionHidden('delete', $role)
        ->assertTableActionVisible('view', $role);
})->with(RoleEnum::cases());

it('offers edit and delete against a custom role', function (): void {
    enterProductTeamPanel();

    $role = Role::factory()->create();

    Livewire::test(ListRoles::class)
        ->assertTableActionVisible('edit', $role)
        ->assertTableActionVisible('delete', $role);
});

it('opens the edit page for a built-in role', function (): void {
    $user = User::factory()->admin()->create();
    $role = Role::findByName(RoleEnum::Owner->value);

    $this->actingAs($user)
        ->get("http://hospitality.test/dashboard/roles/{$role->getKey()}/edit")
        ->assertOk();
});

it('still shows a built-in role read only', function (): void {
    $user = User::factory()->admin()->create();
    $role = Role::findByName(RoleEnum::Owner->value);

    $this->actingAs($user)
        ->get("http://hospitality.test/dashboard/roles/{$role->getKey()}")
        ->assertOk()
        ->assertSee(RoleEnum::Owner->value);
});

it('refuses to rename a built-in role from anywhere', function (): void {
    $role = Role::findByName(RoleEnum::Owner->value);

    expect(fn () => $role->update(['name' => 'renamed']))
        ->toThrow(LogicException::class, 'A built-in role may not be renamed.');

    expect(Role::query()->where('name', RoleEnum::Owner->value)->exists())->toBeTrue();
});

it('refuses to delete a built-in role from anywhere', function (): void {
    $role = Role::findByName(RoleEnum::Owner->value);

    expect(fn () => $role->delete())
        ->toThrow(LogicException::class, 'A built-in role may not be deleted.');

    expect(Role::query()->where('name', RoleEnum::Owner->value)->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Which roles a tenant may hand out
|--------------------------------------------------------------------------
*/

it('withholds any role carrying a product team permission from tenants', function (): void {
    $platformRole = Role::factory()->create();
    $platformRole->givePermissionTo(PermissionEnum::TenantManage->value);

    $assignable = Role::query()->assignableWithinTenant()->pluck('name')->all();

    expect($assignable)->not->toContain($platformRole->name)
        ->and($assignable)->toContain(RoleEnum::Owner->value)
        ->and($assignable)->toContain(RoleEnum::Staff->value);
});

it('offers every built-in role to tenants', function (RoleEnum $roleEnum): void {
    $assignable = Role::query()->assignableWithinTenant()->pluck('name')->all();

    expect($assignable)->toContain($roleEnum->value);
})->with(RoleEnum::cases());
