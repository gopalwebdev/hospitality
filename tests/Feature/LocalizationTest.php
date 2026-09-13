<?php

use App\Enums\Locale;
use App\Enums\Role;
use App\Filament\Tenant\Resources\MenuItems\Pages\ListMenuItems;
use App\Filament\Tenant\Resources\Menus\MenuResource;
use App\Filament\Tenant\Resources\Menus\Pages\ListMenus;
use App\Http\Middleware\SetLocale;
use App\Models\HomeTile;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuItemAddition;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\App;
use Inertia\Testing\AssertableInertia;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * A tenant with one bilingual dish on one bilingual menu.
 *
 * @return array{Tenant, Menu, MenuCategory, MenuItem, MenuItemAddition}
 */
function seedBilingualMenu(): array
{
    $tenant = Tenant::factory()->create(['slug' => 'spice']);

    $menu = Menu::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => ['en' => 'Dinner', 'ta' => 'இரவு உணவு'],
    ]);

    $category = MenuCategory::factory()->inMenu($menu)->create([
        'name' => ['en' => 'Starters', 'ta' => 'தொடக்கங்கள்'],
    ]);

    $item = MenuItem::factory()->inCategory($category)->create([
        'name' => ['en' => 'Paneer Tikka', 'ta' => 'பன்னீர் டிக்கா'],
        'description' => ['en' => 'Charred in the tandoor.', 'ta' => 'தந்தூரில் சுடப்பட்டது.'],
    ]);

    $addition = MenuItemAddition::factory()->onItem($item)->create([
        'name' => ['en' => 'Extra paneer', 'ta' => 'கூடுதல் பன்னீர்'],
    ]);

    return [$tenant, $menu, $category, $item, $addition];
}

function menuUrl(Tenant $tenant, Menu $menu): string
{
    return 'http://'.$tenant->slug.'.tenant-app.test/menus/'.$menu->getKey();
}

/*
|--------------------------------------------------------------------------
| English is the default and the fallback
|--------------------------------------------------------------------------
*/

it('answers in English when a visitor has chosen nothing', function (): void {
    [$tenant, $menu, $category, $item] = seedBilingualMenu();

    $this->get(menuUrl($tenant, $menu))
        ->assertOk()
        ->assertSee($menu->getTranslation('name', 'en'))
        ->assertSee($category->getTranslation('name', 'en'))
        ->assertSee($item->getTranslation('name', 'en'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('locale.current', Locale::English->value)
            // The toggle's destination is worked out server-side, so the button
            // does not need to know the list of languages.
            ->where('locale.next', Locale::Tamil->value)
            ->where('translations.status.open', 'Open'),
        );
});

it('falls back to English for a name that has no translation yet', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => ['en' => 'Dinner'],
    ]);
    $item = MenuItem::factory()
        ->inCategory(MenuCategory::factory()->inMenu($menu)->create(['name' => ['en' => 'Starters']]))
        ->create(['name' => ['en' => 'Paneer Tikka']]);

    // A tenant that has not translated its menu yet is the normal state on
    // day one, and a Tamil-reading guest must still get a readable menu.
    $this->withUnencryptedCookie(SetLocale::COOKIE, Locale::Tamil->value)
        ->get(menuUrl($tenant, $menu))
        ->assertOk()
        ->assertSee('Paneer Tikka')
        ->assertSee('Starters');

    expect($item->getTranslation('name', Locale::Tamil->value))->toBe('Paneer Tikka');
});

/*
|--------------------------------------------------------------------------
| Switching language
|--------------------------------------------------------------------------
*/

it('remembers the chosen language in an unencrypted cookie', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'spice']);

    $response = $this->put(
        'http://spice.tenant-app.test/preferences/language',
        ['locale' => Locale::Tamil->value],
    );

    $response->assertRedirect();

    // Unencrypted so the toggle in React and the middleware read the same
    // value, exactly as the appearance cookie already works.
    $cookie = collect($response->headers->getCookies())
        ->firstWhere(fn ($candidate): bool => $candidate->getName() === SetLocale::COOKIE);

    expect($cookie)->not->toBeNull()
        ->and($cookie->getValue())->toBe(Locale::Tamil->value)
        ->and($tenant->slug)->toBe('spice');
});

it('answers in Tamil once the language has been chosen', function (): void {
    [$tenant, $menu, $category, $item, $addition] = seedBilingualMenu();

    // Read through the props rather than the HTML: Inertia serialises its
    // payload as JSON, which escapes non-ASCII, so assertSee() would be looking
    // for Tamil in a document that spells it \u0ba4 and so on.
    $this->withUnencryptedCookie(SetLocale::COOKIE, Locale::Tamil->value)
        ->get(menuUrl($tenant, $menu))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('locale.current', Locale::Tamil->value)
            ->where('locale.next', Locale::English->value)
            // The tenant's own words, from translated columns...
            ->where('menu.name', $menu->getTranslation('name', 'ta'))
            ->where('sections.0.name', $category->getTranslation('name', 'ta'))
            ->where('sections.0.items.0.name', $item->getTranslation('name', 'ta'))
            ->where('sections.0.items.0.description', $item->getTranslation('description', 'ta'))
            ->where('sections.0.items.0.additions.0.name', $addition->getTranslation('name', 'ta'))
            // ...while the chrome this application supplies stays English,
            // because lang/en is the only language directory there is.
            ->where('translations.status.open', 'Open'),
        );
});

it('translates the tiles on the home screen too', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    HomeTile::factory()->openingMenu($menu)->create([
        'label' => ['en' => 'Our menu', 'ta' => 'எங்கள் மெனு'],
    ]);

    $this->withUnencryptedCookie(SetLocale::COOKIE, Locale::Tamil->value)
        ->get('http://'.$tenant->slug.'.tenant-app.test/')
        ->assertOk()
        ->assertDontSee('Our menu')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('rows.0.tiles.0.label', 'எங்கள் மெனு'),
        );
});

/*
|--------------------------------------------------------------------------
| The tenant panel follows the same choice
|--------------------------------------------------------------------------
*/

it('offers a language switcher in the tenant panel', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, Role::Admin);

    // Posted to the panel's own host: the tenant panel is on a subdomain and a
    // form posting across hosts would lose the session.
    $this->get('http://'.$tenant->slug.'.tenant-app.test/dashboard')
        ->assertOk()
        ->assertSee(route('preferences.language.update', ['tenant' => $tenant->slug]), escape: false)
        ->assertSee(Locale::Tamil->label());
});

it('shows the panel switcher on English until a language is chosen', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, Role::Admin);

    // A picker showing nothing selected is worse than one showing the language
    // in use, so the current locale is normalised rather than compared raw.
    $html = (string) $this->get('http://'.$tenant->slug.'.tenant-app.test/dashboard')
        ->assertOk()
        ->getContent();

    $selected = str($html)->after('id="panel-locale"')->before('</select>')->toString();

    expect($selected)->toContain('value="'.Locale::English->value.'" selected')
        ->and($selected)->not->toContain('value="'.Locale::Tamil->value.'" selected');
});

it('offers a language switcher in the product team panel', function (): void {
    $this->actingAs(User::factory()->superAdmin()->create());

    $this->get('http://tenant-app.test/dashboard')
        ->assertOk()
        ->assertSee(route('panel.language.update'), escape: false)
        ->assertSee(Locale::Tamil->label());
});

it('shows the panel\'s own labels in English and the tenant\'s words in the chosen language', function (): void {
    $tenant = Tenant::factory()->create();
    Menu::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => ['en' => 'Dinner', 'ta' => 'இரவு உணவு'],
    ]);
    enterTenantPanel($tenant, Role::Admin);

    // The two halves are answered differently on purpose: this application's
    // labels are written once, in English, and only what a tenant typed
    // is translated — and that lives in the database.
    $this->withUnencryptedCookie(SetLocale::COOKIE, Locale::Tamil->value)
        ->get('http://'.$tenant->slug.'.tenant-app.test/dashboard/menus')
        ->assertOk()
        ->assertSee(__('panel.menus.create'))
        ->assertSee('இரவு உணவு');
});

it('opens a form in the language the panel was switched to, through the real request', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    enterTenantPanel($tenant, Role::Admin);

    // Cookie, then SetLocale, then the form: the path a browser takes.
    $this->withUnencryptedCookie(SetLocale::COOKIE, Locale::Tamil->value)
        ->get(MenuResource::getUrl('edit', ['record' => $menu, 'tenant' => $tenant]))
        ->assertOk()
        ->assertSee('_locale&quot;:&quot;'.Locale::Tamil->value.'&quot;', escape: false);
});

it('searches a table in the language it is showing, and in English', function (): void {
    $tenant = Tenant::factory()->create();
    $category = MenuCategory::factory()
        ->inMenu(Menu::factory()->create(['tenant_id' => $tenant->getKey()]))
        ->create();
    $paneer = MenuItem::factory()->inCategory($category)->create(['name' => ['en' => 'Paneer Tikka', 'ta' => 'பன்னீர் டிக்கா']]);
    $coffee = MenuItem::factory()->inCategory($category)->create(['name' => ['en' => 'Filter Coffee']]);
    enterTenantPanel($tenant, Role::Admin);

    App::setLocale(Locale::Tamil->value);

    // The column reads Tamil, so a search for the Tamil has to find it...
    Livewire::test(ListMenuItems::class)
        ->searchTable('பன்னீர்')
        ->assertCanSeeTableRecords([$paneer])
        ->assertCanNotSeeTableRecords([$coffee]);

    // ...and an untranslated dish is on screen in English, so that works too.
    Livewire::test(ListMenuItems::class)
        ->searchTable('Coffee')
        ->assertCanSeeTableRecords([$coffee])
        ->assertCanNotSeeTableRecords([$paneer]);
});

it('sorts a table by the name it is showing', function (): void {
    $tenant = Tenant::factory()->create();
    // Latin-script translations on purpose: how Postgres's collation orders
    // Tamil against Latin is not what this is about.
    $alpha = Menu::factory()->create(['tenant_id' => $tenant->getKey(), 'name' => ['en' => 'Alpha', 'ta' => 'Zeta']]);
    $beta = Menu::factory()->create(['tenant_id' => $tenant->getKey(), 'name' => ['en' => 'Beta', 'ta' => 'Apple']]);
    $charlie = Menu::factory()->create(['tenant_id' => $tenant->getKey(), 'name' => ['en' => 'Charlie']]);
    enterTenantPanel($tenant, Role::Admin);

    Livewire::test(ListMenus::class)
        ->sortTable('name')
        ->assertCanSeeTableRecords([$alpha, $beta, $charlie], inOrder: true);

    App::setLocale(Locale::Tamil->value);

    // In Tamil the order is the Tamil one, with the untranslated menu placed
    // by the English it is displayed in rather than by a null.
    Livewire::test(ListMenus::class)
        ->sortTable('name')
        ->assertCanSeeTableRecords([$beta, $charlie, $alpha], inOrder: true);
});

it('finds records from the top bar in the language the panel is showing', function (): void {
    $tenant = Tenant::factory()->create();
    $dinner = Menu::factory()->create(['tenant_id' => $tenant->getKey(), 'name' => ['en' => 'Dinner', 'ta' => 'இரவு உணவு']]);
    Menu::factory()->create(['tenant_id' => $tenant->getKey(), 'name' => ['en' => 'Lunch']]);
    enterTenantPanel($tenant, Role::Admin);

    App::setLocale(Locale::Tamil->value);

    $titles = fn (string $search): array => MenuResource::getGlobalSearchResults($search)
        ->map(fn ($result): string => (string) $result->title)
        ->all();

    expect($titles('இரவு'))->toBe([$dinner->getTranslation('name', 'ta')])
        // It used to match the raw document, where every menu has an "en" key.
        ->and($titles('en'))->toBe([]);
});

it('leaves roles and permissions in English', function (): void {
    // The product team's vocabulary, and code refers to these by name — see
    // .ai/rules/enums.md. Only what a guest reads is translated.
    $this->actingAs(User::factory()->superAdmin()->create());

    $this->withUnencryptedCookie(SetLocale::COOKIE, Locale::Tamil->value)
        ->get('http://tenant-app.test/dashboard/roles')
        ->assertOk()
        ->assertSee('admin');
});

/*
|--------------------------------------------------------------------------
| A language the app does not have
|--------------------------------------------------------------------------
*/

it('refuses a language the app is not available in', function (): void {
    $tenant = Tenant::factory()->create();

    $this->put(
        'http://'.$tenant->slug.'.tenant-app.test/preferences/language',
        ['locale' => 'fr'],
    )->assertSessionHasErrors('locale');
});

it('ignores a tampered cookie rather than breaking the page', function (): void {
    [$tenant, $menu] = seedBilingualMenu();

    // The cookie is unencrypted and therefore visitor-controlled, so a value
    // that is not a language is an ordinary thing to be handed.
    $this->withUnencryptedCookie(SetLocale::COOKIE, 'klingon')
        ->get(menuUrl($tenant, $menu))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('locale.current', Locale::English->value),
        );
});

/*
|--------------------------------------------------------------------------
| The language files themselves
|--------------------------------------------------------------------------
*/

it('ships its own strings in English and in no other language', function (): void {
    // A lang/ta existed and was deliberately deleted: this application's words
    // are written once, and a tenant's words are translated in the database
    // instead. A second directory here would be a second copy of the chrome to
    // keep in step, for a panel whose framework chrome is English anyway.
    expect(array_map(basename(...), glob(lang_path('*'), GLOB_ONLYDIR) ?: []))->toBe(['en'])
        ->and(dotKeys(require lang_path('en/guest.php')))->not->toBeEmpty();
});

it('lists exactly the languages a tenant may write in', function (): void {
    expect(Locale::values())->toBe(['en', 'ta'])
        ->and(Locale::default())->toBe(Locale::English)
        ->and(Locale::English->next())->toBe(Locale::Tamil)
        ->and(Locale::Tamil->next())->toBe(Locale::English);
});

/**
 * Flatten a nested translation array to dotted keys.
 *
 * @param  array<string, mixed>  $values
 * @return array<string, string>
 */
function dotKeys(array $values, string $prefix = ''): array
{
    $flat = [];

    foreach ($values as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        if (is_array($value)) {
            $flat = [...$flat, ...dotKeys($value, $path)];

            continue;
        }

        $flat[$path] = (string) $value;
    }

    return $flat;
}
