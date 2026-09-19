<?php

use App\Enums\Weekday;
use App\Models\Tenant;
use App\Models\TenantOpeningHour;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| When a tenant's doors are open
|--------------------------------------------------------------------------
|
| Hours repeat weekly: a row per day, closed for the day or open between two
| wall-clock times. This is what the guest app reads to say Open or Closed,
| and what refuses an order placed after hours.
|
| The dates below are real days of the week: 14 September 2026 is a Monday.
|
*/

it('is open all week for a tenant that has never set its hours', function (): void {
    $tenant = Tenant::factory()->create();

    // A table nobody has filled in must not shut a tenant out of taking orders.
    expect($tenant->isOpenAt(CarbonImmutable::parse('2026-09-14 03:00')))->toBeTrue()
        ->and($tenant->hoursToday())->toBeNull();
});

it('is open inside the day\'s window and closed either side of it', function (): void {
    $tenant = Tenant::factory()->create();
    TenantOpeningHour::factory()->ofTenant($tenant)->on(Weekday::Monday)->between('09:00', '23:00')->create();

    $monday = CarbonImmutable::parse('2026-09-14 12:00');

    expect($tenant->isOpenAt($monday))->toBeTrue()
        ->and($tenant->isOpenAt($monday->setTime(8, 59)))->toBeFalse()
        // Closing time is the moment the doors shut, not a last minute inside them.
        ->and($tenant->isOpenAt($monday->setTime(23, 0)))->toBeFalse();
});

it('is closed on a day it keeps no hours for at all', function (): void {
    $tenant = Tenant::factory()->create();
    TenantOpeningHour::factory()->ofTenant($tenant)->on(Weekday::Monday)->between('09:00', '23:00')->create();

    // A tenant that has set some of its week has set its week: a day with no
    // row is a day it does not open, unlike a tenant with no week at all.
    expect($tenant->isOpenAt(CarbonImmutable::parse('2026-09-15 12:00')))->toBeFalse();
});

it('stays shut all day on the weekly holiday', function (): void {
    $tenant = Tenant::factory()->create();
    TenantOpeningHour::factory()->ofTenant($tenant)->on(Weekday::Monday)->closed()->create();

    $monday = CarbonImmutable::parse('2026-09-14 12:00');

    expect($tenant->isOpenAt($monday))->toBeFalse()
        // A holiday has no hours to read out.
        ->and($tenant->hoursToday($monday)->opensAt())->toBeNull()
        ->and($tenant->hoursToday($monday)->closesAt())->toBeNull();
});

it('keeps a window that runs past midnight open on the morning after', function (): void {
    $tenant = Tenant::factory()->create();
    TenantOpeningHour::factory()->ofTenant($tenant)->on(Weekday::Monday)->between('18:00', '01:00')->create();
    TenantOpeningHour::factory()->ofTenant($tenant)->on(Weekday::Tuesday)->closed()->create();

    // Monday evening runs into Tuesday, which opens on no hours of its own.
    expect($tenant->isOpenAt(CarbonImmutable::parse('2026-09-14 17:59')))->toBeFalse()
        ->and($tenant->isOpenAt(CarbonImmutable::parse('2026-09-14 20:00')))->toBeTrue()
        ->and($tenant->isOpenAt(CarbonImmutable::parse('2026-09-15 00:30')))->toBeTrue()
        ->and($tenant->isOpenAt(CarbonImmutable::parse('2026-09-15 01:00')))->toBeFalse();
});

it('reads the hours of a day as a clock does', function (): void {
    $tenant = Tenant::factory()->create();
    TenantOpeningHour::factory()->ofTenant($tenant)->on(Weekday::Monday)->between('09:30', '22:45')->create();

    $hours = $tenant->hoursToday(CarbonImmutable::parse('2026-09-14 12:00'));

    // A time column hands back seconds; what a guest reads is hours and minutes.
    expect($hours->opensAt())->toBe('09:30')
        ->and($hours->closesAt())->toBe('22:45');
});

it('reads the week once however many days are asked about', function (): void {
    $tenant = Tenant::factory()->create();
    TenantOpeningHour::factory()->ofTenant($tenant)->on(Weekday::Monday)->between('09:00', '23:00')->create();

    $monday = CarbonImmutable::parse('2026-09-14 12:00');

    DB::enableQueryLog();

    $tenant->isOpenAt($monday);
    $tenant->hoursToday($monday);
    $tenant->isOpenAt($monday->setTime(20, 0));

    // Kept as the relation, never a lazy load per day: a menu page asks three
    // times and every one of them is answered from the first read.
    expect(DB::getQueryLog())->toHaveCount(1);
});
