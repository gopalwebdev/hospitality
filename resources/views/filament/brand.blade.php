{{--
    The mark shown wherever a panel names itself: the sign-in card, and the
    topbar once someone is inside.

    Styles are inline because a panel is served Filament's own compiled CSS,
    which carries its fi- classes and no general utilities to lean on. The
    colours are the panel's own, so each panel brands itself.

    $name overrides the panel's own brandName() — the tenant panel passes the
    signed-in tenant's own name once one is known, and falls back to its
    generic name before that. The product team's panel never passes it: it
    has no tenant to name itself after.
--}}
@php($name ??= $panel->brandName())
<span style="display: inline-flex; align-items: center; gap: 0.625rem;">
    <span
        aria-hidden="true"
        style="
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2rem;
            height: 2rem;
            border-radius: 0.625rem;
            color: var(--primary-500);
            background-color: color-mix(in oklab, var(--primary-500) 12%, transparent);
            border: 1px solid color-mix(in oklab, var(--primary-500) 25%, transparent);
        "
    >
        {{ \Filament\Support\generate_icon_html($panel->icon()) }}
    </span>

    <span style="font-weight: 600; letter-spacing: -0.01em;">
        {{ $name }}
    </span>
</span>
