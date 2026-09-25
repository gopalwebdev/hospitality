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

    .lc--ready::before { background-color: var(--success-500); }
    .lc--pending::before { background-color: var(--warning-500); }
    .lc--preparing::before { background-color: var(--info-500); }

    .lc--ready { background-color: color-mix(in oklab, var(--success-500) 7%, transparent); }
    .lc--pending { background-color: color-mix(in oklab, var(--warning-500) 6%, transparent); }
    .lc--preparing { background-color: color-mix(in oklab, var(--info-500) 5%, transparent); }

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

    .lc-name {
        display: flex;
        align-items: center;
        gap: 0.375rem;
    }

    .lc-kind-icon {
        width: 1rem;
        height: 1rem;
        flex: none;
        color: var(--gray-500);
    }

    :is(.dark) .lc-kind-icon {
        color: var(--gray-400);
    }

    /* How much is waiting, step by step, rather than one figure. */
    .lc-counts {
        display: flex;
        align-items: center;
        gap: 0.375rem;
        flex-wrap: wrap;
    }

    .lc-count {
        font-size: 0.75rem;
        font-weight: 600;
        line-height: 1.5;
        padding: 0.0625rem 0.4375rem;
        border-radius: 9999px;
        white-space: nowrap;
    }

    .lc-count--ready {
        color: var(--success-600);
        background-color: color-mix(in oklab, var(--success-500) 16%, transparent);
    }

    .lc-count--pending {
        color: var(--warning-600);
        background-color: color-mix(in oklab, var(--warning-500) 16%, transparent);
    }

    .lc-count--preparing {
        color: var(--info-600);
        background-color: color-mix(in oklab, var(--info-500) 16%, transparent);
    }

    :is(.dark) .lc-count--ready { color: var(--success-300); }
    :is(.dark) .lc-count--pending { color: var(--warning-300); }
    :is(.dark) .lc-count--preparing { color: var(--info-300); }
</style>
