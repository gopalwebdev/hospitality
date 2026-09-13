<?php

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Filament\Platform\Resources\Users\Pages\CreateUser;
use App\Filament\Platform\Resources\Users\Pages\EditUser;
use App\Filament\Platform\Resources\Users\Pages\ListUsers;
use App\Filament\Platform\Resources\Users\UserResource;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AccountCreatedNotification;
use App\Notifications\SignInCodeNotification;
use App\Policies\UserPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Sign in as the product team and walk the create page as far as the code step.
 *
 * Returns the code that was issued, read back out of the otp log the way the
 * sign-in tests do.
 *
 * @param  array<string, mixed>  $details
 * @return array{0: Testable, 1: string}
 */
function startCreatingAccount(array $details): array
{
    $readCodes = captureIssuedCodes();

    $page = Livewire::test(CreateUser::class)
        ->fillForm($details)
        ->call('create')
        ->assertHasNoFormErrors();

    $codes = $readCodes();

    expect($codes)->toHaveCount(1);

    return [$page, $codes[0]];
}

/*
|--------------------------------------------------------------------------
| Who may reach the page
|--------------------------------------------------------------------------
*/

it('serves the accounts page to the product team', function (): void {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)
        ->get('http://hospitality.test/dashboard/users')
        ->assertOk();
});

it('serves the create, view and edit pages to the product team', function (): void {
    $platform = User::factory()->admin()->create();
    $other = User::factory()->create();

    $this->actingAs($platform);

    $this->get('http://hospitality.test/dashboard/users/create')->assertOk();
    $this->get("http://hospitality.test/dashboard/users/{$other->getKey()}")->assertOk();
    $this->get("http://hospitality.test/dashboard/users/{$other->getKey()}/edit")->assertOk();
});

it('keeps a tenant owner off the accounts page', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->ofTenant($tenant)->create();
    $user->assignRole(RoleEnum::Owner->value);

    $this->actingAs($user)
        ->get('http://hospitality.test/dashboard/users')
        ->assertForbidden();
});

it('hides the accounts resource from everyone but the product team', function (RoleEnum $roleEnum): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->ofTenant($tenant)->create();
    $user->assignRole($roleEnum->value);

    $this->actingAs($user);

    // user.manage is held by tenant roles, so the policy alone would let
    // Owner through. The resource is what keeps this product-team-only.
    expect(UserResource::canAccess())->toBeFalse();
})->with(RoleEnum::cases());

it('shows the accounts resource to the product team', function (): void {
    enterProductTeamPanel();

    expect(UserResource::canAccess())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Listing every account, platform wide
|--------------------------------------------------------------------------
*/

it('lists accounts from every tenant and the platform', function (): void {
    $first = Tenant::factory()->create();
    $second = Tenant::factory()->create();

    $firstStaff = User::factory()->ofTenant($first)->create();
    $secondStaff = User::factory()->ofTenant($second)->create();

    $platform = enterProductTeamPanel();

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$firstStaff, $secondStaff, $platform]);
});

it('filters the platform-wide list down to one tenant', function (): void {
    $spice = Tenant::factory()->create();
    $other = Tenant::factory()->create();

    $spiceStaff = User::factory()->ofTenant($spice)->create();
    $otherStaff = User::factory()->ofTenant($other)->create();

    $platform = enterProductTeamPanel();

    Livewire::test(ListUsers::class)
        ->filterTable('tenant_id', $spice->getKey())
        ->assertCanSeeTableRecords([$spiceStaff])
        ->assertCanNotSeeTableRecords([$otherStaff, $platform]);
});

it('filters the platform-wide list by whether an account belongs to a tenant', function (): void {
    $tenant = Tenant::factory()->create();
    $staff = User::factory()->ofTenant($tenant)->create();
    $platform = enterProductTeamPanel();

    // Where an account belongs is the tenant column alone, so this splits the
    // list the same way the Tenant column reads it.
    Livewire::test(ListUsers::class)
        ->filterTable('belongs_to', 'tenant')
        ->assertCanSeeTableRecords([$staff])
        ->assertCanNotSeeTableRecords([$platform]);

    Livewire::test(ListUsers::class)
        ->filterTable('belongs_to', 'product_team')
        ->assertCanSeeTableRecords([$platform])
        ->assertCanNotSeeTableRecords([$staff]);
});

it('opens the account form with a tenant and role already chosen', function (): void {
    $tenant = Tenant::factory()->create();

    enterProductTeamPanel();

    // How CreateTenant hands a newly onboarded tenant straight on to
    // creating the owner that runs it.
    Livewire::withQueryParams([
        'tenant_id' => $tenant->getKey(),
        'role' => RoleEnum::Owner->value,
    ])
        ->test(CreateUser::class)
        ->assertFormSet([
            'tenant_id' => $tenant->getKey(),
            'roles' => [RoleEnum::Owner->value],
        ]);
});

it('shows an account with no tenant as belonging to the platform', function (): void {
    $platform = enterProductTeamPanel();

    expect($platform->tenant_id)->toBeNull()
        ->and($platform->belongsToProductTeam())->toBeTrue();

    Livewire::test(ListUsers::class)
        ->assertTableColumnStateSet('tenant.name', null, $platform);
});

it('shows a tenant account under its tenant', function (): void {
    $tenant = Tenant::factory()->create(['name' => 'Spice Garden']);
    $staff = User::factory()->ofTenant($tenant)->create();

    enterProductTeamPanel();

    expect($staff->belongsToProductTeam())->toBeFalse();

    Livewire::test(ListUsers::class)
        ->assertTableColumnStateSet('tenant.name', 'Spice Garden', $staff);
});

it('does not treat an account without a tenant as the product team', function (): void {
    // The trap in "null tenant means admin": an account that has not been
    // put on a roster yet has no tenant either, and must stay powerless.
    $stranded = User::factory()->create();

    expect($stranded->tenant_id)->toBeNull()
        ->and($stranded->belongsToProductTeam())->toBeTrue()
        ->and($stranded->isAdmin())->toBeFalse()
        ->and($stranded->can(PermissionEnum::UserManage->value))->toBeFalse();

    $this->actingAs($stranded)
        ->get('http://hospitality.test/dashboard/users')
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Creating an account, confirmed by one-time code
|--------------------------------------------------------------------------
*/

it('emails the admin a code instead of creating the account straight away', function (): void {
    Notification::fake();

    $platform = enterProductTeamPanel();

    [$page] = startCreatingAccount([
        'name' => 'Nadia Rao',
        'email' => 'nadia@example.com',
    ]);

    $page->assertSet('hasRequestedCode', true);

    expect(User::query()->where('email', 'nadia@example.com')->exists())->toBeFalse();

    // The code goes to the person doing the creating, not to the new account.
    Notification::assertSentTo($platform, SignInCodeNotification::class);
    Notification::assertNothingSentTo(User::factory()->make(['email' => 'nadia@example.com']));
});

it('creates the account once the right code is entered', function (): void {
    Notification::fake();

    $tenant = Tenant::factory()->create();

    enterProductTeamPanel();

    [$page, $code] = startCreatingAccount([
        'name' => 'Nadia Rao',
        'email' => 'nadia@example.com',
        'tenant_id' => $tenant->getKey(),
        'roles' => [RoleEnum::Staff->value],
    ]);

    $page->fillForm(['confirmation_code' => $code])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = User::query()->where('email', 'nadia@example.com')->sole();

    expect($created->name)->toBe('Nadia Rao')
        ->and($created->tenant_id)->toBe($tenant->getKey())
        ->and($created->isAdmin())->toBeFalse()
        ->and($created->roles->pluck('name')->all())->toBe([RoleEnum::Staff->value])
        // The tenant column and the roster are written together, or the row
        // would name a tenant the account cannot actually open.
        ->and($created->tenants->pluck('id')->all())->toBe([$tenant->getKey()]);
});

it('tells the new account that it exists', function (): void {
    Notification::fake();

    $tenant = Tenant::factory()->create();

    enterProductTeamPanel();

    [$page, $code] = startCreatingAccount([
        'name' => 'Nadia Rao',
        'email' => 'nadia@example.com',
        'tenant_id' => $tenant->getKey(),
    ]);

    $page->fillForm(['confirmation_code' => $code])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = User::query()->where('email', 'nadia@example.com')->sole();

    Notification::assertSentTo($created, AccountCreatedNotification::class);
});

it('creates the product team with no tenant', function (): void {
    Notification::fake();

    enterProductTeamPanel();

    [$page, $code] = startCreatingAccount([
        'name' => 'Priya Menon',
        'email' => 'priya@example.com',
        'is_admin' => true,
    ]);

    $page->fillForm(['confirmation_code' => $code])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = User::query()->where('email', 'priya@example.com')->sole();

    expect($created->tenant_id)->toBeNull()
        ->and($created->isAdmin())->toBeTrue()
        ->and($created->tenants)->toBeEmpty();
});

it('refuses a wrong code and creates nothing', function (): void {
    Notification::fake();

    enterProductTeamPanel();

    [$page] = startCreatingAccount([
        'name' => 'Nadia Rao',
        'email' => 'nadia@example.com',
    ]);

    $page->fillForm(['confirmation_code' => '000000'])
        ->call('create')
        ->assertHasFormErrors(['confirmation_code']);

    expect(User::query()->where('email', 'nadia@example.com')->exists())->toBeFalse();
});

it('will not let one code create a second account', function (): void {
    Notification::fake();

    enterProductTeamPanel();

    [$page, $firstCode] = startCreatingAccount([
        'name' => 'Nadia Rao',
        'email' => 'nadia@example.com',
    ]);

    $page->fillForm(['confirmation_code' => $firstCode])
        ->call('create')
        ->assertHasNoFormErrors();

    // Past the resend cooldown, so a second code may be asked for at all.
    $this->travel((int) config('otp.resend_cooldown') + 1)->seconds();

    $second = Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'Second Account', 'email' => 'second@example.com'])
        ->call('create')
        ->assertSet('hasRequestedCode', true);

    // The first code was consumed creating the first account, and issuing the
    // second retired it besides. Each creation is confirmed on its own.
    $second->fillForm(['confirmation_code' => $firstCode])
        ->call('create')
        ->assertHasFormErrors(['confirmation_code']);

    expect(User::query()->where('email', 'second@example.com')->exists())->toBeFalse();
});

it('will not issue a second code before the cooldown is up', function (): void {
    Notification::fake();

    enterProductTeamPanel();

    startCreatingAccount([
        'name' => 'Nadia Rao',
        'email' => 'nadia@example.com',
    ]);

    // Straight back for another, well inside the cooldown.
    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'Second Account', 'email' => 'second@example.com'])
        ->call('create')
        ->assertSet('hasRequestedCode', false);
});

it('sends no code for details that would be rejected anyway', function (?string $email): void {
    Notification::fake();

    enterProductTeamPanel();

    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'Nadia Rao', 'email' => $email])
        ->call('create')
        ->assertHasFormErrors(['email'])
        ->assertSet('hasRequestedCode', false);

    Notification::assertNothingSent();
})->with([
    'missing' => null,
    'not an address' => 'not-an-email',
]);

it('refuses an email address that already has an account', function (): void {
    Notification::fake();

    $existing = User::factory()->create(['email' => 'taken@example.com']);

    enterProductTeamPanel();

    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'Nadia Rao', 'email' => $existing->email])
        ->call('create')
        ->assertHasFormErrors(['email' => 'unique'])
        ->assertSet('hasRequestedCode', false);

    Notification::assertNothingSent();
});

it('lets the details be changed before the code is entered', function (): void {
    Notification::fake();

    enterProductTeamPanel();

    [$page] = startCreatingAccount([
        'name' => 'Nadia Rao',
        'email' => 'nadia@example.com',
    ]);

    $page->call('startOver')
        ->assertSet('hasRequestedCode', false);
});

/*
|--------------------------------------------------------------------------
| Editing an account
|--------------------------------------------------------------------------
*/

it('updates an account without letting it move to another tenant', function (): void {
    $from = Tenant::factory()->create();
    $to = Tenant::factory()->create();
    $staff = User::factory()->ofTenant($from)->create();

    enterProductTeamPanel();

    // Where an account belongs is settled when it is opened: the field is
    // disabled, so a tenant submitted here is never dehydrated and the
    // roster it would have moved to is left alone.
    Livewire::test(EditUser::class, ['record' => $staff->getKey()])
        ->assertFormFieldDisabled('tenant_id')
        ->fillForm([
            'name' => 'Renamed Person',
            'tenant_id' => $to->getKey(),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $staff->refresh();

    expect($staff->name)->toBe('Renamed Person')
        ->and($staff->tenant_id)->toBe($from->getKey())
        ->and($staff->tenants->pluck('id')->all())->toBe([$from->getKey()]);
});

it('never offers the product team to an account that belongs to a tenant', function (): void {
    $staff = User::factory()->ofTenant(Tenant::factory()->create())->create();

    enterProductTeamPanel();

    Livewire::test(EditUser::class, ['record' => $staff->getKey()])
        ->assertFormFieldDisabled('is_admin')
        ->fillForm(['is_admin' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($staff->refresh()->isAdmin())->toBeFalse();
});

it('refuses at the model to put a tenant\'s account on the product team', function (): void {
    $staff = User::factory()->ofTenant(Tenant::factory()->create())->create();

    // The backstop behind the disabled toggle: the product team hold every
    // permission on every tenant, so the two may never be combined however
    // the write arrives.
    expect(fn () => $staff->forceFill(['is_admin' => true])->save())
        ->toThrow(LogicException::class);
});

it('lets the product team keep no tenant at all', function (): void {
    $admin = User::factory()->admin()->create();

    enterProductTeamPanel();

    Livewire::test(EditUser::class, ['record' => $admin->getKey()])
        ->assertFormFieldEnabled('is_admin')
        ->fillForm(['name' => 'Renamed'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($admin->refresh()->name)->toBe('Renamed')
        ->and($admin->isAdmin())->toBeTrue()
        ->and($admin->tenant_id)->toBeNull();
});

it('syncs roles so a permission check answers from the new set immediately', function (): void {
    $tenant = Tenant::factory()->create();
    $staff = User::factory()->ofTenant($tenant)->create();
    $staff->assignRole(RoleEnum::Staff->value);

    enterProductTeamPanel();

    // Warm Spatie's registrar cache with the old set, which is what a bare
    // pivot sync would then leave stale for the rest of the request.
    expect($staff->can(PermissionEnum::MenuManage->value))->toBeFalse();

    Livewire::test(EditUser::class, ['record' => $staff->getKey()])
        ->fillForm(['roles' => [RoleEnum::Owner->value]])
        ->call('save')
        ->assertHasNoFormErrors();

    $staff->refresh();

    expect($staff->roles->pluck('name')->all())->toBe([RoleEnum::Owner->value])
        ->and($staff->can(PermissionEnum::MenuManage->value))->toBeTrue();
});

it('clears every role when none are chosen', function (): void {
    $staff = User::factory()->create();
    $staff->assignRole(RoleEnum::Owner->value);

    enterProductTeamPanel();

    Livewire::test(EditUser::class, ['record' => $staff->getKey()])
        ->fillForm(['roles' => []])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($staff->refresh()->roles)->toBeEmpty();
});

it('may hand out a role carrying a product team permission', function (): void {
    $staff = User::factory()->create();

    enterProductTeamPanel();

    // The one place this is allowed: a tenant panel filters these out, and
    // deciding who is the product team is exactly what this panel is for.
    Livewire::test(EditUser::class, ['record' => $staff->getKey()])
        ->fillForm(['roles' => [RoleEnum::Owner->value]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($staff->refresh()->roles->pluck('name')->all())->toBe([RoleEnum::Owner->value]);
});

/*
|--------------------------------------------------------------------------
| Deleting an account
|--------------------------------------------------------------------------
*/

it('deletes another account', function (): void {
    $other = User::factory()->create();

    enterProductTeamPanel();

    Livewire::test(EditUser::class, ['record' => $other->getKey()])
        ->callAction('delete');

    expect(User::query()->whereKey($other->getKey())->exists())->toBeFalse();
});

it('never offers to delete your own account', function (): void {
    $platform = enterProductTeamPanel();

    // Gate::before answers the policy true for an admin before it runs, so
    // this rule has to live on the resource to bind them at all.
    expect(UserResource::canDelete($platform))->toBeFalse();

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('delete', $platform);
});

it('offers to delete somebody else', function (): void {
    $other = User::factory()->create();

    enterProductTeamPanel();

    expect(UserResource::canDelete($other))->toBeTrue();

    Livewire::test(ListUsers::class)
        ->assertTableActionVisible('delete', $other);
});

it('refuses self deletion at the policy too', function (): void {
    $platform = User::factory()->admin()->create();
    $other = User::factory()->create();

    expect($platform->can('delete', $other))->toBeTrue()
        ->and((new UserPolicy)->delete($platform, $platform))->toBeFalse();
});

it('leaves an account standing when its tenant is deleted', function (): void {
    $tenant = Tenant::factory()->create();
    $staff = User::factory()->ofTenant($tenant)->create();

    $tenant->delete();

    // The tenant column falls back to null rather than taking the account with
    // it: accounts are platform-wide and outlive any one tenant.
    expect(User::query()->whereKey($staff->getKey())->exists())->toBeTrue()
        ->and($staff->refresh()->tenant_id)->toBeNull()
        ->and($staff->isAdmin())->toBeFalse();
});
