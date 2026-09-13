<?php

use App\Enums\Permission as PermissionEnum;
use App\Enums\PermissionGroup;
use App\Enums\Role as RoleEnum;
use App\Filament\Platform\Resources\Permissions\Pages\CreatePermission;
use App\Filament\Platform\Resources\Permissions\Pages\EditPermission;
use App\Filament\Platform\Resources\Permissions\Pages\ListPermissions;
use App\Filament\Platform\Resources\Permissions\PermissionResource;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Who may manage permissions
|--------------------------------------------------------------------------
*/

it('lets the product team manage permissions', function (): void {
    $user = User::factory()->superAdmin()->create();
    $permission = Permission::factory()->create();

    expect($user->can('viewAny', Permission::class))->toBeTrue()
        ->and($user->can('create', Permission::class))->toBeTrue()
        ->and($user->can('update', $permission))->toBeTrue()
        ->and($user->can('delete', $permission))->toBeTrue();
});

it('refuses permission management to every tenant role', function (RoleEnum $roleEnum): void {
    $user = User::factory()->create();
    $user->assignRole($roleEnum->value);
    $permission = Permission::factory()->create();

    expect($user->can('viewAny', Permission::class))->toBeFalse()
        ->and($user->can('create', Permission::class))->toBeFalse()
        ->and($user->can('update', $permission))->toBeFalse()
        ->and($user->can('delete', $permission))->toBeFalse();
})->with(RoleEnum::cases());

it('keeps a tenant admin off the permissions page', function (): void {
    $user = User::factory()->create();
    $user->assignRole(RoleEnum::Admin->value);

    $this->actingAs($user)
        ->get('http://restaurant-app.test/dashboard/permissions')
        ->assertForbidden();
});

it('serves the permissions page to the product team', function (): void {
    $user = User::factory()->superAdmin()->create();

    $this->actingAs($user)
        ->get('http://restaurant-app.test/dashboard/permissions')
        ->assertOk();
});

/*
|--------------------------------------------------------------------------
| Creating, editing and deleting
|--------------------------------------------------------------------------
*/

it('lists every permission the application declares', function (): void {
    enterProductTeamPanel();

    Livewire::test(ListPermissions::class)
        // More permissions than fit on a page, and this is about all of them.
        ->set('tableRecordsPerPage', 50)
        ->assertCanSeeTableRecords(Permission::query()->get());
});

it('creates a permission', function (): void {
    enterProductTeamPanel();

    Livewire::test(CreatePermission::class)
        ->fillForm(['name' => 'table.reserve'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Permission::query()->where('name', 'table.reserve')->exists())->toBeTrue();
});

it('updates a custom permission', function (): void {
    $permission = Permission::factory()->create(['name' => 'table.reserve']);
    enterProductTeamPanel();

    Livewire::test(EditPermission::class, ['record' => $permission->getKey()])
        ->fillForm(['name' => 'table.book'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($permission->refresh()->name)->toBe('table.book');
});

it('deletes a custom permission no role holds', function (): void {
    $permission = Permission::factory()->create();
    enterProductTeamPanel();

    Livewire::test(EditPermission::class, ['record' => $permission->getKey()])
        ->callAction('delete');

    expect(Permission::query()->whereKey($permission->getKey())->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| A permission a role holds may not be deleted
|--------------------------------------------------------------------------
|
| Deleting one out from under a role narrows what that role grants, and
| nothing afterwards says it happened. It comes off every role first.
|
*/

it('refuses to delete a permission a role holds', function (): void {
    $permission = Permission::factory()->create();
    $role = Role::factory()->create();
    $role->givePermissionTo($permission->name);

    enterProductTeamPanel();

    expect($permission->isInUse())->toBeTrue()
        ->and($permission->undeletableReason())->toContain('held by a role')
        ->and(PermissionResource::canDelete($permission))->toBeFalse();

    Livewire::test(ListPermissions::class)
        ->assertTableActionHidden('delete', $permission);

    expect(Permission::query()->whereKey($permission->getKey())->exists())->toBeTrue();
});

it('refuses to delete a permission a role holds from anywhere', function (): void {
    $permission = Permission::factory()->create();
    $role = Role::factory()->create();
    $role->givePermissionTo($permission->name);

    expect(fn () => $permission->delete())
        ->toThrow(LogicException::class, 'A permission held by a role may not be deleted.');

    expect(Permission::query()->whereKey($permission->getKey())->exists())->toBeTrue();
});

it('deletes a permission once no role holds it', function (): void {
    $permission = Permission::factory()->create();
    $role = Role::factory()->create();
    $role->givePermissionTo($permission->name);

    enterProductTeamPanel();

    expect(PermissionResource::canDelete($permission))->toBeFalse();

    $role->revokePermissionTo($permission->name);

    expect($permission->refresh()->undeletableReason())->toBeNull()
        ->and(PermissionResource::canDelete($permission))->toBeTrue();

    Livewire::test(EditPermission::class, ['record' => $permission->getKey()])
        ->callAction('delete');

    expect(Permission::query()->whereKey($permission->getKey())->exists())->toBeFalse();
});

it('says on the table why a permission cannot be deleted', function (): void {
    $held = Permission::factory()->create();
    Role::factory()->create()->givePermissionTo($held->name);

    $free = Permission::factory()->create();

    enterProductTeamPanel();

    Livewire::test(ListPermissions::class)
        ->set('tableRecordsPerPage', 50)
        ->assertTableColumnStateSet('is_deletable', false, $held)
        ->assertTableColumnStateSet('is_deletable', true, $free);
});

it('refuses to delete a built-in permission every role holds', function (): void {
    // Both guards apply to menu.view at once; the built-in one is reported,
    // because it is the one that can never be worked around.
    $permission = Permission::query()->where('name', PermissionEnum::MenuView->value)->sole();

    expect($permission->isInUse())->toBeTrue()
        ->and($permission->undeletableReason())->toContain('declared in code')
        ->and(PermissionResource::canDelete($permission))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Permissions are shown by category
|--------------------------------------------------------------------------
|
| Category is not a column: it is read off the subject half of the name, so a
| permission added from the panel is filed without anything being declared
| for it, and Other is whatever no group claims.
|
*/

it('files every declared permission under a category', function (PermissionEnum $permissionEnum): void {
    $permission = Permission::query()->where('name', $permissionEnum->value)->sole();

    expect($permission->group())->toBe($permissionEnum->group())
        ->and($permission->group())->not->toBe(PermissionGroup::Other);
})->with(PermissionEnum::cases());

it('files a permission by the subject half of its name', function (string $name, PermissionGroup $expected): void {
    expect(PermissionGroup::forPermissionName($name))->toBe($expected);
})->with([
    'menu' => ['menu.view', PermissionGroup::Menu],
    'order' => ['order.manage', PermissionGroup::Orders],
    'user' => ['user.manage', PermissionGroup::People],
    'settings' => ['settings.manage', PermissionGroup::Tenant],
    'tenant' => ['tenant.manage', PermissionGroup::ProductTeam],
    'role' => ['role.manage', PermissionGroup::ProductTeam],
    'permission' => ['permission.manage', PermissionGroup::ProductTeam],
    'unknown subject' => ['kitchen.expedite', PermissionGroup::Other],
]);

it('keeps the product team category and the product-team-only list in step', function (): void {
    // Two ways of asking the same question — the group a permission is shown
    // under, and whether a tenant may be offered a role holding it. If
    // they drift, the panel files something as harmless that is not.
    $byGroup = array_map(
        fn (PermissionEnum $permission): string => $permission->value,
        PermissionEnum::inGroup(PermissionGroup::ProductTeam),
    );

    expect($byGroup)->toEqualCanonicalizing(PermissionEnum::productTeamOnlyValues());
});

it('shows the category on each row', function (): void {
    enterProductTeamPanel();

    $menuView = Permission::query()->where('name', PermissionEnum::MenuView->value)->sole();
    $roleManage = Permission::query()->where('name', PermissionEnum::RoleManage->value)->sole();

    Livewire::test(ListPermissions::class)
        ->set('tableRecordsPerPage', 50)
        ->assertTableColumnStateSet('category', 'Menu', $menuView)
        ->assertTableColumnStateSet('category', 'Product team', $roleManage);
});

it('groups the table by category out of the box', function (): void {
    enterProductTeamPanel();

    Livewire::test(ListPermissions::class)
        ->assertSet('tableGrouping', 'category:asc');
});

it('filters the table down to one category', function (): void {
    enterProductTeamPanel();

    $menu = Permission::query()->where('name', 'like', 'menu.%')->get();
    $orders = Permission::query()->where('name', 'like', 'order.%')->get();

    Livewire::test(ListPermissions::class)
        ->set('tableRecordsPerPage', 50)
        ->filterTable('category', PermissionGroup::Menu->value)
        ->assertCanSeeTableRecords($menu)
        ->assertCanNotSeeTableRecords($orders);
});

it('filters down to only the permissions no category claims', function (): void {
    $custom = Permission::factory()->create(['name' => 'kitchen.expedite']);

    enterProductTeamPanel();

    Livewire::test(ListPermissions::class)
        ->set('tableRecordsPerPage', 50)
        ->filterTable('category', PermissionGroup::Other->value)
        ->assertCanSeeTableRecords([$custom])
        ->assertCanNotSeeTableRecords(
            Permission::query()->where('name', 'like', 'menu.%')->get(),
        );
});

it('requires a permission name written as subject.ability', function (?string $name): void {
    enterProductTeamPanel();

    Livewire::test(CreatePermission::class)
        ->fillForm(['name' => $name])
        ->call('create')
        ->assertHasFormErrors(['name']);
})->with([
    'missing' => null,
    'no ability' => 'table',
    'uppercase' => 'Table.reserve',
    'spaced' => 'table reserve',
    'two dots' => 'table.reserve.now',
]);

it('refuses a permission name that already exists', function (): void {
    enterProductTeamPanel();

    Livewire::test(CreatePermission::class)
        ->fillForm(['name' => PermissionEnum::MenuView->value])
        ->call('create')
        ->assertHasFormErrors(['name' => 'unique']);
});

/*
|--------------------------------------------------------------------------
| Built-in permissions are owned by the code
|--------------------------------------------------------------------------
|
| Their names are what the application checks with can(), so renaming or
| deleting one revokes access without saying so.
|
*/

it('refuses to edit or delete a built-in permission, even for the product team', function (PermissionEnum $permissionEnum): void {
    enterProductTeamPanel();

    $permission = Permission::query()->where('name', $permissionEnum->value)->sole();

    expect($permission->isBuiltIn())->toBeTrue()
        ->and(PermissionResource::canEdit($permission))->toBeFalse()
        ->and(PermissionResource::canDelete($permission))->toBeFalse();
})->with(PermissionEnum::cases());

it('allows editing and deleting a custom permission', function (): void {
    enterProductTeamPanel();

    $permission = Permission::factory()->create();

    expect($permission->isBuiltIn())->toBeFalse()
        ->and(PermissionResource::canEdit($permission))->toBeTrue()
        ->and(PermissionResource::canDelete($permission))->toBeTrue();
});

it('offers no edit or delete button against a built-in permission', function (): void {
    enterProductTeamPanel();

    $permission = Permission::query()->where('name', PermissionEnum::MenuView->value)->sole();

    Livewire::test(ListPermissions::class)
        ->assertTableActionHidden('edit', $permission)
        ->assertTableActionHidden('delete', $permission)
        ->assertTableActionVisible('view', $permission);
});

it('offers edit and delete against a custom permission', function (): void {
    enterProductTeamPanel();

    $permission = Permission::factory()->create();

    Livewire::test(ListPermissions::class)
        ->assertTableActionVisible('edit', $permission)
        ->assertTableActionVisible('delete', $permission);
});

it('closes the edit page for a built-in permission', function (): void {
    $user = User::factory()->superAdmin()->create();
    $permission = Permission::query()->where('name', PermissionEnum::MenuView->value)->sole();

    $this->actingAs($user)
        ->get("http://restaurant-app.test/dashboard/permissions/{$permission->getKey()}/edit")
        ->assertForbidden();
});

it('still shows a built-in permission read only', function (): void {
    $user = User::factory()->superAdmin()->create();
    $permission = Permission::query()->where('name', PermissionEnum::MenuView->value)->sole();

    $this->actingAs($user)
        ->get("http://restaurant-app.test/dashboard/permissions/{$permission->getKey()}")
        ->assertOk()
        ->assertSee(PermissionEnum::MenuView->value);
});

it('refuses to rename a built-in permission from anywhere', function (): void {
    $permission = Permission::query()->where('name', PermissionEnum::MenuView->value)->sole();

    expect(fn () => $permission->update(['name' => 'menu.peek']))
        ->toThrow(LogicException::class, 'A built-in permission may not be renamed.');

    expect(Permission::query()->where('name', PermissionEnum::MenuView->value)->exists())->toBeTrue();
});

it('refuses to delete a built-in permission from anywhere', function (): void {
    $permission = Permission::query()->where('name', PermissionEnum::MenuView->value)->sole();

    expect(fn () => $permission->delete())
        ->toThrow(LogicException::class, 'A built-in permission may not be deleted.');

    expect(Permission::query()->where('name', PermissionEnum::MenuView->value)->exists())->toBeTrue();
});
