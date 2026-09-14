<?php

use App\Models\Tenant;

it('keeps both panels from taking a negative number or drawing arrows in a number box', function (string $url): void {
    Tenant::factory()->create(['slug' => 't1']);

    // On the sign-in page, so on every page after it: a price, a rate or a
    // count is never negative, and the browser's arrows are hidden.
    $this->get($url)
        ->assertOk()
        ->assertSee("input[type='number']::-webkit-inner-spin-button", escape: false)
        ->assertSee("['-', '+', 'e', 'E'].includes(event.key)", escape: false);
})->with([
    'product team' => 'http://hospitality.test/dashboard/login',
    'tenant' => 'http://t1.hospitality.test/dashboard/login',
]);
