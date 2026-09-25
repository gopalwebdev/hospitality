{{--
    What one location reads as: what kind of place it is, and what is being
    worked there right now.

    Takes $location and $activity (one entry of
    App\Actions\Orders\ReadLocationActivity). The wrapper is the including
    page's business — the orders page's floor layout wraps it in a section with
    actions, the counter's picker in a button — so that only what would
    otherwise drift lives here.

    **No money.** The project owner's instruction: a card says where the work
    is. What a room owes is the list layout's business and the Locations page's
    Settle, and a card carrying a ₹0.00 said nothing three times over.

    **A quiet card says one thing and no more.** It carried a "Clear" badge as
    well, and that went for the same reason: a badge on every card says nothing.
--}}
<div class="lc-head">
    <div style="min-width: 0;">
        <div class="lc-name">
            {{-- The kind at a glance: a bed for a room, a table for a table. --}}
            <x-filament::icon
                :icon="$location->kind->icon()"
                class="lc-kind-icon"
            />
            <span>{{ $location->name }}</span>
        </div>

        <div class="lc-muted">
            {{ $location->kind->label() }}@if (filled($location->code)) · {{ $location->code }}@endif
            @if ($location->capacity !== null)
                · {{ trans_choice('panel.board.capacity', $location->capacity, ['count' => $location->capacity]) }}
            @endif
        </div>
    </div>

    @if ($activity['state']->isOpen())
        <x-filament::badge :color="$activity['state']->color()" :icon="$activity['state']->icon()">
            {{ $activity['state']->label() }}
        </x-filament::badge>
    @endif
</div>

<div class="lc-figures">
    @if ($activity['state']->isOpen())
        {{-- What is actually waiting, step by step, rather than one total. --}}
        <span class="lc-counts">
            @if ($activity['ready'] > 0)
                <span class="lc-count lc-count--ready">{{ __('panel.board.n_ready', ['count' => $activity['ready']]) }}</span>
            @endif

            @if ($activity['pending'] > 0)
                <span class="lc-count lc-count--pending">{{ __('panel.board.n_pending', ['count' => $activity['pending']]) }}</span>
            @endif

            @if ($activity['preparing'] > 0)
                <span class="lc-count lc-count--preparing">{{ __('panel.board.n_preparing', ['count' => $activity['preparing']]) }}</span>
            @endif
        </span>

        @if ($activity['lastOrderedAt'] !== null)
            <span class="lc-muted">{{ $activity['lastOrderedAt']->diffForHumans(short: true) }}</span>
        @endif
    @else
        <span class="lc-muted">{{ __('panel.board.nothing_open') }}</span>
    @endif
</div>
