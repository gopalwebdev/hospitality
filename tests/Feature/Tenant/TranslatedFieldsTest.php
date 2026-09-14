<?php

use App\Enums\Locale;
use App\Enums\Role as RoleEnum;
use App\Filament\Schemas\TranslatedFields;
use App\Filament\Tenant\Resources\Menus\Pages\ArrangeMenu;
use App\Filament\Tenant\Resources\Menus\Pages\EditMenu;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/*
|--------------------------------------------------------------------------
| One box, one switcher
|--------------------------------------------------------------------------
|
| A translated field has an input per language underneath, but only the
| switched-to one is on screen. What matters is that the ones off screen still
| reach the save, and that the rules built on English still fire while Tamil is
| the language being looked at.
|
*/

it('shows only the language the form is switched to', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    enterTenantPanel($tenant, RoleEnum::Owner);

    // The schema argument is omitted on purpose: the helpers resolve the
    // mounted action's own schema when it is left off.
    Livewire::test(ArrangeMenu::class, ['record' => $menu->getKey()])
        ->mountAction(TestAction::make('createCategory')->table())
        ->assertSchemaComponentStateSet(TranslatedFields::LOCALE_KEY, Locale::default()->value)
        ->assertSchemaComponentVisible('name.'.Locale::English->value)
        ->assertSchemaComponentHidden('name.'.Locale::Tamil->value);
});

it('opens on English on a form that was filled, not just a blank one', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    // `default()` only applies to a form filled with nothing, so every edit
    // form — and every modal handed data — came up with neither language lit.
    // The switcher decides which box is on screen and which language the
    // "required in English" rule reads, so nothing selected is not a cosmetic
    // state.
    $english = Locale::default()->value;

    Livewire::test(EditMenu::class, ['record' => $menu->getKey()])
        ->assertSchemaComponentStateSet(TranslatedFields::LOCALE_KEY, $english);

    Livewire::test(ArrangeMenu::class, ['record' => $menu->getKey()])
        ->mountAction(TestAction::make('rename')->table('category-'.$category->getKey()))
        ->assertSchemaComponentStateSet(TranslatedFields::LOCALE_KEY, $english);

    Livewire::test(ArrangeMenu::class, ['record' => $menu->getKey()])
        ->mountAction(TestAction::make('createSubCategory')->table('category-'.$category->getKey()))
        ->assertSchemaComponentStateSet(TranslatedFields::LOCALE_KEY, $english);
});

it('opens on the language the panel is being worked in', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    // What SetLocale does with the top bar's choice. Someone who switched the
    // panel to Tamil is there to write Tamil, so every form — a page's or a
    // modal's, blank or filled — starts there instead of on English.
    App::setLocale(Locale::Tamil->value);

    Livewire::test(EditMenu::class, ['record' => $menu->getKey()])
        ->assertSchemaComponentStateSet(TranslatedFields::LOCALE_KEY, Locale::Tamil->value)
        ->assertSchemaComponentVisible('name.'.Locale::Tamil->value)
        ->assertSchemaComponentHidden('name.'.Locale::English->value);

    Livewire::test(ArrangeMenu::class, ['record' => $menu->getKey()])
        ->mountAction(TestAction::make('createCategory')->table())
        ->assertSchemaComponentStateSet(TranslatedFields::LOCALE_KEY, Locale::Tamil->value);

    Livewire::test(ArrangeMenu::class, ['record' => $menu->getKey()])
        ->mountAction(TestAction::make('rename')->table('category-'.$category->getKey()))
        ->assertSchemaComponentStateSet(TranslatedFields::LOCALE_KEY, Locale::Tamil->value);
});

it('falls back to English when the form is handed a language it does not have', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    // The switcher is a form field like any other and can arrive as anything.
    Livewire::test(ArrangeMenu::class, ['record' => $menu->getKey()])
        ->mountAction(TestAction::make('createCategory')->table())
        ->set('mountedActions.0.data.'.TranslatedFields::LOCALE_KEY, 'klingon')
        ->assertSchemaComponentVisible('name.'.Locale::English->value);
});

it('shows the other language once the switcher is moved', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ArrangeMenu::class, ['record' => $menu->getKey()])
        ->mountAction(TestAction::make('createCategory')->table())
        ->set('mountedActions.0.data.'.TranslatedFields::LOCALE_KEY, Locale::Tamil->value)
        ->assertSchemaComponentHidden('name.'.Locale::English->value)
        ->assertSchemaComponentVisible('name.'.Locale::Tamil->value);
});

it('keeps the language that is off screen when the form is saved', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    enterTenantPanel($tenant, RoleEnum::Owner);

    // The whole point of dehydratedWhenHidden(): typing the Tamil and saving
    // while English is off screen must not blank the English.
    Livewire::test(ArrangeMenu::class, ['record' => $menu->getKey()])
        ->callAction(TestAction::make('createCategory')->table(), [
            'name' => [Locale::English->value => 'Starters', Locale::Tamil->value => 'தொடக்கங்கள்'],
            TranslatedFields::LOCALE_KEY => Locale::Tamil->value,
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    $category = MenuCategory::query()->withoutGlobalScopes()->sole();

    expect($category->getTranslations('name'))->toBe([
        Locale::English->value => 'Starters',
        Locale::Tamil->value => 'தொடக்கங்கள்',
    ]);
});

it('still insists on English while Tamil is the language on screen', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    enterTenantPanel($tenant, RoleEnum::Owner);

    // The required rule lives on the English input, which is hidden here — so
    // it rides on every language's input and reads English out of the state.
    Livewire::test(ArrangeMenu::class, ['record' => $menu->getKey()])
        ->callAction(TestAction::make('createCategory')->table(), [
            'name' => [Locale::Tamil->value => 'தொடக்கங்கள்'],
            TranslatedFields::LOCALE_KEY => Locale::Tamil->value,
            'is_active' => true,
        ])
        ->assertHasActionErrors(['name.'.Locale::Tamil->value]);

    expect(MenuCategory::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('still refuses a duplicate English name while Tamil is on screen', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    MenuCategory::factory()->inMenu($menu)->create(['name' => [Locale::English->value => 'Starters']]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    // Uniqueness is built on the English name in the database, so a save that
    // passed validation here would fail at the index instead.
    Livewire::test(ArrangeMenu::class, ['record' => $menu->getKey()])
        ->callAction(TestAction::make('createCategory')->table(), [
            'name' => [Locale::English->value => 'Starters', Locale::Tamil->value => 'வேறு'],
            TranslatedFields::LOCALE_KEY => Locale::Tamil->value,
            'is_active' => true,
        ])
        ->assertHasActionErrors(['name.'.Locale::Tamil->value]);

    expect(MenuCategory::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('fills the switcher form with every language when editing', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create([
        'name' => [Locale::English->value => 'Starters', Locale::Tamil->value => 'தொடக்கங்கள்'],
    ]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ArrangeMenu::class, ['record' => $menu->getKey()])
        ->mountAction(TestAction::make('rename')->table('category-'.$category->getKey()))
        ->assertActionDataSet([
            'name' => [Locale::English->value => 'Starters', Locale::Tamil->value => 'தொடக்கங்கள்'],
        ]);
});
