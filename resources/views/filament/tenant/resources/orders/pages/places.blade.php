{{--
The floor: every room, table and delivery point at once, with what is open
at each. One of the two ways the orders page is read (ListOrders).

A card's own look and markup are the two shared partials below, which the
counter's location picker draws too, so the two cannot drift apart. What is
left here is this layout's own: its summary strip, its toolbar and the
actions under each card.

Inline, because a panel is served Filament's own compiled CSS and none of
ours (.ai/rules/filament.md), so everything with a theme of its own —
badges, buttons, inputs, sections — is a Filament component rather than
markup of mine.

Cards arrive sorted by ReadFloor: whatever is owing first, newest order at
the top, and everywhere quiet last.
--}}
@include('filament.tenant.partials.location-card-styles')

<style>
    .floor-layout {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .floor-toolbar {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-wrap: wrap;
    }

    /* Full width on a phone, then beside the rest of the toolbar. */
    .floor-toolbar-search {
        flex: 1 1 100%;
        min-width: 0;
    }

    @media (min-width: 48rem) {
        .floor-toolbar-search {
            flex: 1 1 14rem;
        }
    }

    /*
        One strip of figures rather than four tiles. Four sections stacked a
        card's worth of height above the floor on a phone, for four numbers;
        as one wrapping row they cost a line and the cards start higher up.
    */
    .floor-summary {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem 1.75rem;
    }

    .floor-stat {
        display: flex;
        align-items: baseline;
        gap: 0.5rem;
    }

    .floor-stat-value {
        font-size: 1.375rem;
        font-weight: 600;
        font-variant-numeric: tabular-nums;
        line-height: 1.2;
    }

    .floor-stat-value--ready { color: var(--success-600); }
    .floor-stat-value--pending { color: var(--warning-600); }
    .floor-stat-value--preparing { color: var(--info-600); }

    :is(.dark) .floor-stat-value--ready { color: var(--success-400); }
    :is(.dark) .floor-stat-value--pending { color: var(--warning-400); }
    :is(.dark) .floor-stat-value--preparing { color: var(--info-400); }

    .floor-live {
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
    }

    .floor-live-dot {
        width: 0.5rem;
        height: 0.5rem;
        border-radius: 9999px;
        background-color: var(--success-500);
        animation: floor-pulse 2s ease-in-out infinite;
    }

    @keyframes floor-pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.25; }
    }

    @media (prefers-reduced-motion: reduce) {
        .floor-live-dot { animation: none; }
    }
</style>

@php
    $summary = $this->summary();
    $cards = $this->cards();
@endphp

{{-- Its own column: outside a page's schema, nothing spaces these for us. --}}
<div class="floor-layout">
@if (! $this->hasAnyLocation())
    <x-filament::section>
        <x-filament::empty-state
            :heading="__('panel.locations.empty_heading')"
            :description="__('panel.locations.empty_description')"
            icon="heroicon-o-map-pin"
            :contained="false"
        >
            <x-slot:footer>
                <x-filament::button tag="a" :href="$this->locationsUrl()">
                    {{ __('panel.locations.create') }}
                </x-filament::button>
            </x-slot:footer>
        </x-filament::empty-state>
    </x-filament::section>
@else
    {{--
        What is waiting across the whole floor, above the cards, so nobody has
        to count them up by eye — and only while there is something to count.
        Three zeroes over a quiet floor is the same noise as the "Clear" badge
        the project owner had taken off the cards.

        No money here either: a bill is the list layout's business.
    --}}
    @if ($summary['ready'] + $summary['pending'] + $summary['preparing'] > 0)
    <x-filament::section compact>
        <div class="floor-summary">
            <span class="floor-stat">
                <span class="floor-stat-value floor-stat-value--ready">{{ $summary['ready'] }}</span>
                <span class="lc-muted">{{ __('panel.board.ready') }}</span>
            </span>

            <span class="floor-stat">
                <span class="floor-stat-value floor-stat-value--pending">{{ $summary['pending'] }}</span>
                <span class="lc-muted">{{ __('panel.board.pending') }}</span>
            </span>

            <span class="floor-stat">
                <span class="floor-stat-value floor-stat-value--preparing">{{ $summary['preparing'] }}</span>
                <span class="lc-muted">{{ __('panel.board.preparing') }}</span>
            </span>

            <span class="floor-stat">
                <span class="floor-stat-value">{{ $summary['locations'] }}</span>
                <span class="lc-muted">{{ __('panel.board.active_locations') }}</span>
            </span>
        </div>
    </x-filament::section>
    @endif

    <div class="floor-toolbar">
        <div class="floor-toolbar-search">
            <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
                <x-filament::input
                    type="search"
                    wire:model.live.debounce.300ms="floorSearch"
                    :placeholder="__('panel.board.search')"
                />
            </x-filament::input.wrapper>
        </div>

        <x-filament::tabs contained>
            <x-filament::tabs.item
                :active="$this->kind === null"
                wire:click="$set('kind', null)"
            >
                {{ __('panel.locations.all_tab') }}
            </x-filament::tabs.item>

            @foreach ($this->kinds() as $kind)
                <x-filament::tabs.item
                    :active="$this->kind === $kind->value"
                    :icon="$kind->icon()"
                    wire:click="$set('kind', '{{ $kind->value }}')"
                >
                    {{ $kind->label() }}
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>

        <x-filament::button
            :color="$this->onlyOpen ? 'primary' : 'gray'"
            :outlined="! $this->onlyOpen"
            icon="heroicon-o-funnel"
            wire:click="$toggle('onlyOpen')"
        >
            {{ __('panel.board.only_open') }}
        </x-filament::button>

        <span class="floor-live lc-muted">
            <span class="floor-live-dot"></span>
            {{ __('panel.board.live', ['seconds' => $this->pollInterval()]) }}
        </span>
    </div>

    {{--
        The one thing that polls. A tick is ReadLocationActivity's single
        grouped query, whatever the board is drawing; see the page's own
        docblock for why this is not a websocket.
    --}}
    <div class="lc-grid" wire:poll.{{ $this->pollInterval() }}>
        @forelse ($cards as $card)
            @php
                $location = $card['location'];
                $state = $card['activity']['state'];
            @endphp

            {{--
                The card itself opens the counter at that place, where what is
                running there is listed and the next order is taken. It carried
                a "Take order" button and an icon button beside it, and the
                project owner had both off: the card was the obvious thing to
                press and pressing it did nothing.
            --}}
            <a
                href="{{ $this->locationUrl($location) }}"
                wire:navigate.hover
                class="lc-link"
                wire:key="card-{{ $location->getKey() }}"
            >
                <x-filament::section compact class="lc lc--{{ $state->value }}">
                    @include('filament.tenant.partials.location-card', [
                        'location' => $location,
                        'activity' => $card['activity'],
                    ])
                </x-filament::section>
            </a>
        @empty
            <div style="grid-column: 1 / -1;">
                <x-filament::section>
                    <x-filament::empty-state
                        :heading="__('panel.board.none_heading')"
                        :description="__('panel.board.none_description')"
                        icon="heroicon-o-magnifying-glass"
                        :contained="false"
                    />
                </x-filament::section>
            </div>
        @endforelse
    </div>
@endif
</div>
