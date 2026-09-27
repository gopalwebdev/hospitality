{{--
    The look of a location card, shared by the orders page's floor and the counter's
    location picker so the two cannot drift apart.

    Inline, because a panel is served Filament's own compiled CSS and none of
    ours (.ai/rules/filament.md). Colours are Filament's palette variables
    through color-mix, the way the menu arrangement page uses them, so a tint
    reads correctly over whatever background the theme gives the card.

    Included once per page; the markup partial beside it (location-card) is
    included once per card.

    Note that a CSS comment below is served to the browser, unlike this one —
    so the history lives here. The whole card is one link now: it carried a
    worded button to take an order and an icon button crossing to the list,
    and the project owner had both off. The card was the obvious thing to
    press and pressing it did nothing, and two small targets on a card is one
    thumb-sized target fewer than none.
--}}
<style>
    /*
        One card per row on a phone, then as many as fit. A card is one line
        now, so a floor of thirty rooms is a few rows rather than a scroll.
    */
    .lc-grid {
        display: grid;
        gap: 0.75rem;
        grid-template-columns: 1fr;
    }

    @media (min-width: 30rem) {
        .lc-grid {
            grid-template-columns: repeat(auto-fill, minmax(11rem, 1fr));
        }
    }

    @media (min-width: 48rem) {
        .lc-grid {
            grid-template-columns: repeat(auto-fill, minmax(12.5rem, 1fr));
        }
    }

    /*
        Every card is the same height because every card holds the same one
        line. Nothing here forces it: the state is the colour, not a badge
        and a row of chips that made a busy card twice the size of a quiet
        one, so there is no longer anything to vary.
    */
    .lc {
        position: relative;
        overflow: hidden;
    }

    /* The state, as a bar down the leading edge and a wash over the card. */
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

    .lc--ready { background-color: color-mix(in oklab, var(--success-500) 12%, transparent); }
    .lc--pending { background-color: color-mix(in oklab, var(--warning-500) 12%, transparent); }
    .lc--preparing { background-color: color-mix(in oklab, var(--info-500) 10%, transparent); }

    /*
        The whole card is the tap target. An anchor around the section rather
        than a control inside it, so a thumb cannot miss and so the browser's
        own "open in a new tab" works.
    */
    .lc-link {
        display: block;
        border-radius: 0.75rem;
        color: inherit;
        text-decoration: none;
        transition: transform 120ms ease;
    }

    .lc-link .lc {
        transition: background-color 120ms ease;
    }

    .lc-link:hover .lc {
        background-color: color-mix(in oklab, var(--primary-500) 10%, transparent);
    }

    .lc-link:active {
        transform: scale(0.99);
    }

    .lc-link:focus-visible {
        outline: 2px solid var(--primary-500);
        outline-offset: 2px;
    }

    @media (prefers-reduced-motion: reduce) {
        .lc-link,
        .lc-link .lc {
            transition: none;
        }

        .lc-link:active {
            transform: none;
        }
    }

    .lc-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        min-width: 0;
    }

    .lc-name {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        min-width: 0;
        font-size: 1rem;
        font-weight: 600;
        line-height: 1.4;
    }

    /* One line: a long name is cut rather than made a card twice the height. */
    .lc-name-text {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .lc-kind-icon {
        width: 1.125rem;
        height: 1.125rem;
        flex: none;
        color: var(--gray-500);
    }

    :is(.dark) .lc-kind-icon {
        color: var(--gray-400);
    }

    /*
        How many orders are open here. One fixed slot on every card, so a
        card with a 1 and a card with an 11 are the same shape, and a quiet
        card is the same height with the slot empty.
    */
    .lc-count {
        flex: none;
        display: inline-grid;
        place-items: center;
        min-width: 1.5rem;
        height: 1.5rem;
        padding-inline: 0.375rem;
        border-radius: 9999px;
        font-size: 0.8125rem;
        font-weight: 700;
        font-variant-numeric: tabular-nums;
        line-height: 1;
    }

    .lc-count--ready {
        color: var(--success-700);
        background-color: color-mix(in oklab, var(--success-500) 30%, transparent);
    }

    .lc-count--pending {
        color: var(--warning-700);
        background-color: color-mix(in oklab, var(--warning-500) 30%, transparent);
    }

    .lc-count--preparing {
        color: var(--info-700);
        background-color: color-mix(in oklab, var(--info-500) 30%, transparent);
    }

    :is(.dark) .lc-count--ready { color: var(--success-200); }
    :is(.dark) .lc-count--pending { color: var(--warning-200); }
    :is(.dark) .lc-count--preparing { color: var(--info-200); }

    .lc-muted {
        color: var(--gray-500);
        font-size: 0.75rem;
        line-height: 1.5;
    }

    :is(.dark) .lc-muted {
        color: var(--gray-400);
    }
</style>
