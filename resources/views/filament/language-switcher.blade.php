{{--
    Switching the language a panel is worked in.

    A plain form rather than a Livewire action: the choice is a cookie, and the
    page has to be re-rendered by the server anyway because the menu names it
    shows come out of translated columns.

    Styled inline. Panels are served Filament's own compiled CSS, which carries
    its fi- classes and no general Tailwind utilities — see
    .ai/rules/filament.md.

    The action is worked out per panel because a form must post to the host it
    was rendered on: the tenant panel lives on a tenant's subdomain, the
    product team's on the root domain, and a cross-host post loses the session.
--}}
@php
    $tenant = \Filament\Facades\Filament::getTenant();

    $action = $tenant instanceof \App\Models\Tenant
        ? route('preferences.language.update', ['tenant' => $tenant->slug])
        : route('panel.language.update');

    // Normalised rather than compared raw: an application locale that is not one
    // of these cases would leave every option unselected, and a language picker
    // showing nothing is worse than one showing the language in use. English is
    // what anything unrecognised means here.
    $current = \App\Enums\Locale::fromRequestValue(app()->getLocale());
@endphp

<form method="POST" action="{{ $action }}" style="display:flex;align-items:center">
    @csrf
    @method('PUT')

    <label for="panel-locale" class="fi-sr-only">
        {{ __('panel.language.label') }}
    </label>

    <select
        id="panel-locale"
        name="locale"
        onchange="this.form.requestSubmit()"
        style="appearance:none;border:1px solid rgba(0,0,0,.12);border-radius:.5rem;background:transparent;color:inherit;font-size:.875rem;line-height:1.25rem;padding:.375rem 1.75rem .375rem .625rem;cursor:pointer;background-image:url('data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 20 20%22 fill=%22currentColor%22><path d=%22M5.5 7.5 10 12l4.5-4.5%22 stroke=%22currentColor%22 stroke-width=%221.5%22 fill=%22none%22 stroke-linecap=%22round%22 stroke-linejoin=%22round%22/></svg>');background-repeat:no-repeat;background-position:right .375rem center;background-size:1rem"
        title="{{ __('panel.language.label') }}"
    >
        @foreach (\App\Enums\Locale::cases() as $locale)
            <option value="{{ $locale->value }}" @selected($locale === $current)>
                {{ $locale->label() }}
            </option>
        @endforeach
    </select>
</form>
