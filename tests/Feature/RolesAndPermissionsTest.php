<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('persists every permission declared in the enum', function (): void {
    expect(PermissionModel::query()->pluck('name')->all())
        ->toEqualCanonicalizing(Permission::values());
});

it('persists every role declared in the enum', function (): void {
    expect(RoleModel::query()->pluck('name')->all())
        ->toEqualCanonicalizing(Role::values());
});

it('grants each role exactly the permissions it declares', function (Role $role): void {
    $granted = RoleModel::findByName($role->value)->permissions->pluck('name')->all();

    expect($granted)->toEqualCanonicalizing($role->permissionValues());
})->with(Role::cases());

it('can be seeded repeatedly without duplicating rows', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(RoleModel::query()->count())->toBe(count(Role::cases()))
        ->and(PermissionModel::query()->count())->toBe(count(Permission::cases()));
});

it('gives a guest ordering rights but no management rights', function (): void {
    $user = User::factory()->create();
    $user->assignRole(Role::Guest->value);

    expect($user->can(Permission::OrderCreate->value))->toBeTrue()
        ->and($user->can(Permission::MenuView->value))->toBeTrue()
        ->and($user->can(Permission::MenuManage->value))->toBeFalse()
        ->and($user->can(Permission::UserManage->value))->toBeFalse();
});

it('gives a super admin every permission without holding a role', function (): void {
    $user = User::factory()->superAdmin()->create();

    expect($user->roles)->toBeEmpty();

    foreach (Permission::cases() as $permission) {
        expect($user->can($permission->value))->toBeTrue();
    }
});

it('gives a tenant admin everything except product team management', function (): void {
    $user = User::factory()->create();
    $user->assignRole(Role::Admin->value);

    foreach (Permission::cases() as $permission) {
        expect($user->can($permission->value))->toBe(! $permission->isProductTeamOnly());
    }
});

it('withholds every product team permission from every tenant role', function (Role $role): void {
    $user = User::factory()->create();
    $user->assignRole($role->value);

    foreach (Permission::productTeamOnly() as $permission) {
        expect($user->can($permission->value))->toBeFalse();
    }
})->with(Role::cases());

it('counts managing tenants, roles and permissions as product team only', function (): void {
    expect(Permission::productTeamOnlyValues())->toEqualCanonicalizing([
        Permission::TenantManage->value,
        Permission::RoleManage->value,
        Permission::PermissionManage->value,
    ]);
});
