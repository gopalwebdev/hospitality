{{--
    Switching the language a panel is worked in.

    A plain form rather than a Livewire action: the choice is a cookie, and the
    page has to be re-rendered by the server anyway because the menu names it
    shows come out of translated columns.

    **A dropdown of Filament's own, not a bare `<select>`.** It was a select
    styled with a hand-written border, chevron and padding, which read as a
    form field parked in the topbar beside controls that were not. The project
    owner asked for a better one. Each language is a `dropdown.list.item`
    submitting the form it sits inside — `name="locale"` and a `value` on the
    button, so the choice posts with no JavaScript and the current one is
    ticked.

    The dropdown must **not** be teleported: the panel stays inside the form,
    which is the only thing making those buttons submit it.

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
    // of these cases would leave every option unticked, and a language picker
    // showing nothing is worse than one showing the language in use. English is
    // what anything unrecognised means here.
    $current = \App\Enums\Locale::fromRequestValue(app()->getLocale());
@endphp

<form method="POST" action="{{ $action }}" style="display:flex;align-items:center">
    @csrf
    @method('PUT')

    <x-filament::dropdown placement="bottom-end" width="xs">
        <x-slot name="trigger">
            <x-filament::button
                type="button"
                color="gray"
                size="sm"
                icon="heroicon-m-language"
                icon-position="before"
                :tooltip="__('panel.language.label')"
            >
                {{ $current->label() }}
            </x-filament::button>
        </x-slot>

        <x-filament::dropdown.header icon="heroicon-m-language">
            {{ __('panel.language.label') }}
        </x-filament::dropdown.header>

        <x-filament::dropdown.list>
            @foreach (\App\Enums\Locale::cases() as $locale)
                <x-filament::dropdown.list.item
                    type="submit"
                    name="locale"
                    value="{{ $locale->value }}"
                    :icon="$locale === $current ? 'heroicon-m-check' : null"
                    :color="$locale === $current ? 'primary' : 'gray'"
                >
                    {{ $locale->label() }}
                </x-filament::dropdown.list.item>
            @endforeach
        </x-filament::dropdown.list>
    </x-filament::dropdown>
</form>
