<?php

use App\Models\Tenant;
use Inertia\Testing\AssertableInertia;

it('serves the guest home screen from a tenant\'s own subdomain', function (): void {
    $tenant = Tenant::factory()->create([
        'slug' => 't1',
        'name' => 'Tenant One',
    ]);

    // A guest scanning a QR code lands on the rows the tenant arranged,
    // and walks from there into a menu or a PDF.
    $this->get('http://t1.hospitality.test/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('home')
            ->where('tenant.name', $tenant->name)
            ->where('tenant.slug', 't1')
            ->has('rows', 0),
        );
});

it('serves the marketing page on the root domain', function (): void {
    $this->get('http://hospitality.test/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->component('welcome'));
});

it('returns 404 for a subdomain with no tenant behind it', function (): void {
    $this->get('http://nope.hospitality.test/')->assertNotFound();
});

it('takes an inactive tenant storefront offline', function (): void {
    Tenant::factory()->inactive()->create(['slug' => 'closed']);

    $this->get('http://closed.hospitality.test/')->assertNotFound();
});
