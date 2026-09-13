<?php

use App\Enums\FilamentPanel;
use App\Enums\Role;
use App\Filament\Auth\OtpLogin;
use App\Filament\Platform\Auth\Login as PlatformLogin;
use App\Filament\Tenant\Auth\Login as TenantLogin;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->readCodes = captureIssuedCodes();
});

/**
 * A member of the product team, who may use the platform panel.
 */
function superAdmin(): User
{
    return User::factory()->superAdmin()->create();
}

/**
 * A tenant administrator, who may use only their own tenant panel.
 */
function tenantAdmin(Tenant $tenant): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::Admin->value);
    $user->tenants()->attach($tenant);

    return $user;
}

/**
 * Each panel's sign-in page, with an account that may use it.
 *
 * @return array<string, Closure(): array{class-string<OtpLogin>, User}>
 */
dataset('sign-in pages', [
    'product team panel' => [fn (): array => [
        PlatformLogin::class,
        tap(superAdmin(), fn (): mixed => Filament::setCurrentPanel(FilamentPanel::Platform->value)),
    ]],
    'tenant panel' => [fn (): array => [
        TenantLogin::class,
        tap(
            tenantAdmin(Tenant::factory()->create(['slug' => 't1'])),
            fn (): mixed => Filament::setCurrentPanel(FilamentPanel::Tenant->value),
        ),
    ]],
]);

/*
|--------------------------------------------------------------------------
| Moving from asking for a code to entering it
|--------------------------------------------------------------------------
|
| Both steps are one Livewire component, and the content schema is built
| before the action that moves between them runs. Anything the form resolves
| eagerly therefore describes the step that has just been left, which is how
| the page came to show "Email me a code" while asking for the code.
|
*/

it('asks for an address before it asks for a code', function (Closure $setUp): void {
    [$page] = $setUp();

    Livewire::test($page)
        ->assertSee('Email me a code')
        ->assertDontSee('Send a new code')
        ->assertDontSee('Use a different email');
})->with('sign-in pages');

it('offers to sign in, not to send another first code, once a code is out', function (Closure $setUp): void {
    [$page, $user] = $setUp();

    Livewire::test($page)
        ->fillForm(['email' => $user->email])
        ->call('requestCode')
        ->assertSee('Enter your code')
        ->assertSee('Sign in')
        ->assertSee('Send a new code')
        ->assertSee('Use a different email')
        ->assertDontSee('Email me a code');
})->with('sign-in pages');

it('submits the code rather than asking for another one', function (Closure $setUp): void {
    [$page, $user] = $setUp();

    Livewire::test($page)
        ->fillForm(['email' => $user->email])
        ->call('requestCode')
        ->assertSeeHtml('wire:submit="authenticate"');
})->with('sign-in pages');

/*
|--------------------------------------------------------------------------
| Requesting a code
|--------------------------------------------------------------------------
*/

it('issues a code and moves on to asking for it', function (): void {
    Filament::setCurrentPanel(FilamentPanel::Platform->value);
    $user = superAdmin();

    Livewire::test(PlatformLogin::class)
        ->fillForm(['email' => $user->email])
        ->call('requestCode')
        ->assertHasNoFormErrors()
        ->assertSet('hasRequestedCode', true);

    expect(($this->readCodes)())->toHaveCount(1);
});

it('matches the address regardless of how it is capitalised', function (): void {
    Filament::setCurrentPanel(FilamentPanel::Platform->value);
    $user = superAdmin();

    Livewire::test(PlatformLogin::class)
        ->fillForm(['email' => strtoupper($user->email)])
        ->call('requestCode');

    expect(($this->readCodes)())->toHaveCount(1);
});

it('turns down an address with no account behind it', function (): void {
    Filament::setCurrentPanel(FilamentPanel::Platform->value);

    Livewire::test(PlatformLogin::class)
        ->fillForm(['email' => 'nobody@example.com'])
        ->call('requestCode')
        ->assertHasFormErrors(['email'])
        ->assertSet('hasRequestedCode', false);

    expect(($this->readCodes)())->toBeEmpty();
});

it('turns down an address that is not an email at all', function (): void {
    Filament::setCurrentPanel(FilamentPanel::Platform->value);

    Livewire::test(PlatformLogin::class)
        ->fillForm(['email' => 'not-an-email'])
        ->call('requestCode')
        ->assertHasFormErrors(['email' => 'email'])
        ->assertSet('hasRequestedCode', false);

    expect(($this->readCodes)())->toBeEmpty();
});

it('issues no code to a user who cannot reach the panel', function (): void {
    Filament::setCurrentPanel(FilamentPanel::Platform->value);
    $user = tenantAdmin(Tenant::factory()->create(['slug' => 't1']));

    // A real account, but not one that can use this panel. It is refused in
    // exactly the same words as an address nobody owns.
    Livewire::test(PlatformLogin::class)
        ->fillForm(['email' => $user->email])
        ->call('requestCode')
        ->assertHasFormErrors(['email'])
        ->assertSet('hasRequestedCode', false);

    expect(($this->readCodes)())->toBeEmpty();
});

it('will not request a code without an address', function (): void {
    Filament::setCurrentPanel(FilamentPanel::Platform->value);

    Livewire::test(PlatformLogin::class)
        ->fillForm(['email' => ''])
        ->call('requestCode')
        ->assertHasFormErrors(['email' => 'required']);

    expect(($this->readCodes)())->toBeEmpty();
});

it('sends a new code without the code field being filled in', function (): void {
    Filament::setCurrentPanel(FilamentPanel::Platform->value);
    $user = superAdmin();

    $component = Livewire::test(PlatformLogin::class)
        ->fillForm(['email' => $user->email])
        ->call('requestCode');

    $this->travel((int) config('otp.resend_cooldown') + 1)->seconds();

    // The code field is visible and required by now, but asking for a
    // replacement must not trip over it being empty.
    $component->call('requestCode')->assertHasNoFormErrors();

    expect(($this->readCodes)())->toHaveCount(2);
});

it('turns down a second code asked for inside the cooldown', function (): void {
    Filament::setCurrentPanel(FilamentPanel::Platform->value);
    $user = superAdmin();

    Livewire::test(PlatformLogin::class)
        ->fillForm(['email' => $user->email])
        ->call('requestCode')
        ->call('requestCode')
        ->assertHasNoFormErrors();

    expect(($this->readCodes)())->toHaveCount(1);
});

it('stops issuing codes once the allowance is used up', function (): void {
    Filament::setCurrentPanel(FilamentPanel::Platform->value);
    $user = superAdmin();
    $maxSends = (int) config('otp.max_sends');

    $component = Livewire::test(PlatformLogin::class)
        ->fillForm(['email' => $user->email]);

    // One more request than the allowance, each after the cooldown has passed.
    for ($request = 0; $request <= $maxSends; $request++) {
        $component->call('requestCode');
        $this->travel((int) config('otp.resend_cooldown') + 1)->seconds();
    }

    expect(($this->readCodes)())->toHaveCount($maxSends);
});

it('goes back to the address step on request', function (): void {
    Filament::setCurrentPanel(FilamentPanel::Platform->value);
    $user = superAdmin();

    Livewire::test(PlatformLogin::class)
        ->fillForm(['email' => $user->email])
        ->call('requestCode')
        ->assertSet('hasRequestedCode', true)
        ->call('startOver')
        ->assertSet('hasRequestedCode', false)
        ->assertFormSet(['email' => $user->email]);
});

/*
|--------------------------------------------------------------------------
| The code step
|--------------------------------------------------------------------------
*/

it('keeps the sign-in button disabled until every digit is typed', function (string $code, bool $isEnabled): void {
    Filament::setCurrentPanel(FilamentPanel::Platform->value);
    $user = superAdmin();

    $component = Livewire::test(PlatformLogin::class)
        ->fillForm(['email' => $user->email])
        ->call('requestCode')
        ->fillForm(['email' => $user->email, 'code' => $code])
        ->assertSee('Sign in');

    // The disabled submit button, as rendered. Filament's action assertions
    // only reach actions registered on the component, and these live in the
    // form's footer schema.
    $disabledSubmitButton = 'disabled="disabled" type="submit"';

    $isEnabled
        ? $component->assertDontSeeHtml($disabledSubmitButton)
        : $component->assertSeeHtml($disabledSubmitButton);
})->with([
    'nothing typed' => ['', false],
    'half a code' => ['123', false],
    'one digit short' => ['12345', false],
    'every digit' => ['123456', true],
]);

it('says how many digits the code has', function (): void {
    Filament::setCurrentPanel(FilamentPanel::Platform->value);
    $user = superAdmin();

    Livewire::test(PlatformLogin::class)
        ->fillForm(['email' => $user->email])
        ->call('requestCode')
        ->assertSee(sprintf('%d-digit code', (int) config('otp.length')));
});

it('turns down a code that is not all digits', function (): void {
    Filament::setCurrentPanel(FilamentPanel::Platform->value);
    $user = superAdmin();

    $component = Livewire::test(PlatformLogin::class)
        ->fillForm(['email' => $user->email])
        ->call('requestCode');

    $component
        ->fillForm(['code' => str_repeat('a', (int) config('otp.length'))])
        ->call('authenticate')
        ->assertHasFormErrors(['code']);

    $this->assertGuest();
});

/*
|--------------------------------------------------------------------------
| Signing in
|--------------------------------------------------------------------------
*/

it('signs a super admin in with the code that was issued', function (): void {
    Filament::setCurrentPanel(FilamentPanel::Platform->value);
    $user = superAdmin();

    $component = Livewire::test(PlatformLogin::class)
        ->fillForm(['email' => $user->email])
        ->call('requestCode');

    $component
        ->fillForm(['email' => $user->email, 'code' => ($this->readCodes)()[0]])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    $this->assertAuthenticatedAs($user);
});

it('signs a tenant admin into their own tenant panel', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 't1']);
    Filament::setCurrentPanel(FilamentPanel::Tenant->value);
    $user = tenantAdmin($tenant);

    $component = Livewire::test(TenantLogin::class)
        ->fillForm(['email' => $user->email])
        ->call('requestCode');

    $component
        ->fillForm(['email' => $user->email, 'code' => ($this->readCodes)()[0]])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    $this->assertAuthenticatedAs($user);
});

it('signs the product team in on a tenant subdomain they staff no part of', function (): void {
    // The users resource in the tenant panel puts a tenancy global scope on
    // User, and sign-in looks accounts up by address. The product team are on no
    // tenant's roster, so were that scope to reach the lookup they would
    // be told their own account does not exist. Booting the panel is what
    // registers the scope, so this signs in with it in place.
    $user = superAdmin();

    Tenant::factory()->create(['slug' => 't1']);
    Filament::setCurrentPanel(FilamentPanel::Tenant->value);
    Filament::bootCurrentPanel();

    $component = Livewire::test(TenantLogin::class)
        ->fillForm(['email' => $user->email])
        ->call('requestCode')
        ->assertHasNoFormErrors();

    $component
        ->fillForm(['email' => $user->email, 'code' => ($this->readCodes)()[0]])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    $this->assertAuthenticatedAs($user);
});

it('identifies no tenant until someone has signed in', function (): void {
    // Why the scope above cannot reach sign-in: Filament resolves the tenant
    // from the authenticated user, so a visitor at the login page has none,
    // and the scope leaves every query alone while that is true.
    Tenant::factory()->create(['slug' => 't1']);

    $this->get('http://t1.tenant-app.test/dashboard/login')->assertOk();

    expect(Filament::getTenant())->toBeNull();
});

it('still refuses a code to an address with no account at all', function (): void {
    Tenant::factory()->create(['slug' => 't1']);
    Filament::setCurrentPanel(FilamentPanel::Tenant->value);
    Filament::bootCurrentPanel();

    Livewire::test(TenantLogin::class)
        ->fillForm(['email' => 'nobody@example.com'])
        ->call('requestCode')
        ->assertHasFormErrors(['email']);
});

it('marks the address verified once a code has been used', function (): void {
    Filament::setCurrentPanel(FilamentPanel::Platform->value);
    $user = superAdmin();
    $user->forceFill(['email_verified_at' => null])->save();

    $component = Livewire::test(PlatformLogin::class)
        ->fillForm(['email' => $user->email])
        ->call('requestCode');

    $component
        ->fillForm(['email' => $user->email, 'code' => ($this->readCodes)()[0]])
        ->call('authenticate');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('turns the first submit into a code request', function (): void {
    Filament::setCurrentPanel(FilamentPanel::Platform->value);
    $user = superAdmin();

    Livewire::test(PlatformLogin::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->assertSet('hasRequestedCode', true);

    expect(($this->readCodes)())->toHaveCount(1);
    $this->assertGuest();
});

/*
|--------------------------------------------------------------------------
| Rejecting a code
|--------------------------------------------------------------------------
*/

it('rejects a code that is not the one issued', function (): void {
    Filament::setCurrentPanel(FilamentPanel::Platform->value);
    $user = superAdmin();

    $component = Livewire::test(PlatformLogin::class)
        ->fillForm(['email' => $user->email])
        ->call('requestCode');

    $wrongCode = str_pad((string) ((int) ($this->readCodes)()[0] + 1), 6, '0', STR_PAD_LEFT);

    $component
        ->fillForm(['email' => $user->email, 'code' => $wrongCode])
        ->call('authenticate')
        ->assertHasFormErrors(['code']);

    $this->assertGuest();
});

it('rejects a code that has expired', function (): void {
    Filament::setCurrentPanel(FilamentPanel::Platform->value);
    $user = superAdmin();

    $component = Livewire::test(PlatformLogin::class)
        ->fillForm(['email' => $user->email])
        ->call('requestCode');

    $code = ($this->readCodes)()[0];

    $this->travel((int) config('otp.ttl') + 1)->minutes();

    $component
        ->fillForm(['email' => $user->email, 'code' => $code])
        ->call('authenticate')
        ->assertHasFormErrors(['code']);

    $this->assertGuest();
});

it('re-checks panel access when the code is submitted', function (): void {
    Filament::setCurrentPanel(FilamentPanel::Platform->value);
    $user = superAdmin();

    $component = Livewire::test(PlatformLogin::class)
        ->fillForm(['email' => $user->email])
        ->call('requestCode');

    $code = ($this->readCodes)()[0];

    // Access is taken away between asking for the code and using it.
    $user->forceFill(['is_super_admin' => false])->save();

    $component
        ->fillForm(['email' => $user->email, 'code' => $code])
        ->call('authenticate')
        ->assertHasFormErrors(['code']);

    $this->assertGuest();
});

it('will not let a code be used twice', function (): void {
    Filament::setCurrentPanel(FilamentPanel::Platform->value);
    $user = superAdmin();

    $component = Livewire::test(PlatformLogin::class)
        ->fillForm(['email' => $user->email])
        ->call('requestCode');

    $code = ($this->readCodes)()[0];

    $component->fillForm(['email' => $user->email, 'code' => $code])->call('authenticate');
    $this->assertAuthenticatedAs($user);

    auth()->logout();

    // Replaying the very same submission on the very same page.
    $component->call('authenticate')->assertHasFormErrors(['code']);

    $this->assertGuest();
});

it('will not accept a code against a swapped-in address', function (): void {
    Filament::setCurrentPanel(FilamentPanel::Platform->value);
    $user = superAdmin();

    $component = Livewire::test(PlatformLogin::class)
        ->fillForm(['email' => $user->email])
        ->call('requestCode');

    $code = ($this->readCodes)()[0];

    // The address is read-only on screen, so this is someone editing the
    // request. The code belongs to another address and must not be honoured.
    $component
        ->fillForm(['email' => 'nobody@example.com', 'code' => $code])
        ->call('authenticate')
        ->assertHasFormErrors(['code']);

    $this->assertGuest();
});
