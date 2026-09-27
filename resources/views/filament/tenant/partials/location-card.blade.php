{{--
    What one location reads as on the floor and in the counter's picker: its
    name, its kind as an icon, and a colour saying whether anything is being
    worked there.

    Takes $location and $activity (one entry of
    App\Actions\Orders\ReadLocationActivity). The wrapper is the including
    page's business — the orders page's floor layout wraps it in a link to the
    counter, the counter's picker in a button, and both put the `lc--<state>`
    class that colours it on that wrapper — so only what would otherwise drift
    lives here.

    **Every card is one line, so every card is the same size.** The project
    owner's instruction. It drew a state badge, a chip per step ("1 pending")
    and how long ago the newest order landed, which made a busy card twice the
    height of a quiet one and a floor of them read as rubble. What is
    happening is the **colour**; pressing the card opens the counter, which
    says it in full.

    **No money**, for the same reason it never carried any: a card says where
    the work is, and what a room owes is the list layout's business and the
    Locations page's Settle.

    **The kind is the icon and nothing else.** "Room" under "Room 101" was the
    same word twice, and "Area" under "Poolside" was a label nobody reads
    second.

    The one thing still written out is read aloud rather than drawn: colour on
    its own is no use to a screen reader, and green beside amber is no use to
    a good share of the people working a floor.
--}}
<div class="lc-head">
    <div class="lc-name">
        {{-- The kind at a glance: a bed for a room, a table for a table. --}}
        <x-filament::icon
            :icon="$location->kind->icon()"
            class="lc-kind-icon"
        />

        <span class="lc-name-text">{{ $location->name }}</span>
    </div>

    @if ($activity['state']->isOpen())
        {{--
            How many orders are open here, in the one place every card keeps
            for it. A number and a colour, because those are the two things
            that may differ between two cards — everything else is the same
            on all of them, which is what keeps a wall of them readable.
        --}}
        <span class="lc-count lc-count--{{ $activity['state']->value }}">{{ $activity['orders'] }}</span>

        <span class="fi-sr-only">
            {{ implode(', ', array_filter([
                $activity['ready'] > 0 ? __('panel.board.n_ready', ['count' => $activity['ready']]) : null,
                $activity['pending'] > 0 ? __('panel.board.n_pending', ['count' => $activity['pending']]) : null,
                $activity['preparing'] > 0 ? __('panel.board.n_preparing', ['count' => $activity['preparing']]) : null,
            ])) }}
        </span>
    @endif
</div>
