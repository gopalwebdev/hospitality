{{--
    The look of a location card, shared by the orders page's floor and the counter's
    location picker so the two cannot drift apart.

    Inline, because a panel is served Filament's own compiled CSS and none of
    ours (.ai/rules/filament.md). Colours are Filament's palette variables
    through color-mix, the way the menu arrangement page uses them, so a tint
    reads correctly over whatever background the theme gives the card.

    Included once per page; the markup partial beside it (location-card) is
    included once per card.
--}}
<style>
    /* One card per row on a phone, then as many as fit. */
    .lc-grid {
        display: grid;
        gap: 0.75rem;
        grid-template-columns: 1fr;
    }

    @media (min-width: 30rem) {
        .lc-grid {
            grid-template-columns: repeat(auto-fill, minmax(13.5rem, 1fr));
        }
    }

    .lc {
        position: relative;
        overflow: hidden;
    }

    .lc::before {
        content: '';
        position: absolute;
        inset-block: 0;
        inset-inline-start: 0;
        width: 4px;
        background-color: var(--gray-400);
    }

    .lc--just-ordered::before {
        background-color: var(--warning-500);
    }

    .lc--running::before {
        background-color: var(--info-500);
    }

    .lc--just-ordered {
        background-color: color-mix(in oklab, var(--warning-500) 6%, transparent);
    }

    .lc--running {
        background-color: color-mix(in oklab, var(--info-500) 5%, transparent);
    }

    .lc-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.5rem;
    }

    .lc-name {
        font-size: 1rem;
        font-weight: 600;
        line-height: 1.4;
    }

    .lc-muted {
        color: var(--gray-500);
        font-size: 0.75rem;
        line-height: 1.5;
    }

    :is(.dark) .lc-muted {
        color: var(--gray-400);
    }

    .lc-figures {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 0.5rem;
        margin-top: 0.75rem;
    }

    .lc-owing {
        font-size: 1.125rem;
        font-weight: 600;
        font-variant-numeric: tabular-nums;
    }
</style>
