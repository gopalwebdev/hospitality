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

    .floor-actions {
        display: flex;
        align-items: center;
        gap: 0.375rem;
        margin-top: 0.75rem;
        flex-wrap: wrap;
    }

    .floor-toolbar {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-wrap: wrap;
    }

    .floor-toolbar-search {
        flex: 1 1 12rem;
        min-width: 0;
    }

    .floor-summary {
        display: grid;
        gap: 0.75rem;
        grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));
    }

    .floor-stat-value {
        font-size: 1.5rem;
        font-weight: 600;
        font-variant-numeric: tabular-nums;
        line-height: 1.2;
    }

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
    $currency = $this->currency();
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
        What the whole floor owes, above the cards, so nobody adds them up by
        eye — and only while there is something to add up. Three zeroes over a
        quiet floor is the same noise as the "Clear" badge the project owner
        had taken off the cards.
    --}}
    @if ($summary['openOrders'] > 0)
    <div class="floor-summary">
        <x-filament::section compact>
            <div class="lc-muted">{{ __('panel.board.active_locations') }}</div>
            <div class="floor-stat-value">{{ $summary['locations'] }}</div>
        </x-filament::section>

        <x-filament::section compact>
            <div class="lc-muted">{{ __('panel.board.open_orders') }}</div>
            <div class="floor-stat-value">{{ $summary['openOrders'] }}</div>
        </x-filament::section>

        <x-filament::section compact>
            <div class="lc-muted">{{ __('panel.orders.outstanding') }}</div>
            <div class="floor-stat-value">{{ $currency->format($summary['outstanding']) }}</div>
        </x-filament::section>
    </div>
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

            <x-filament::section compact class="lc lc--{{ $state->value }}">
                @include('filament.tenant.partials.location-card', [
                    'location' => $location,
                    'activity' => $card['activity'],
                    'currency' => $currency,
                ])

                <div class="floor-actions">
                    <x-filament::button
                        tag="a"
                        size="sm"
                        icon="heroicon-o-plus"
                        :href="$this->takeOrderUrl($location)"
                    >
                        {{ __('panel.board.take_order') }}
                    </x-filament::button>

                    {{ ($this->settleAction)(['location' => $location->getKey()]) }}

                    @if ($card['activity']['openOrders'] > 0)
                        {{-- A card is the question; the list is the answer, so the filter is set on the way across. --}}
                        <x-filament::icon-button
                            size="sm"
                            color="gray"
                            icon="heroicon-o-list-bullet"
                            wire:click="showOrdersAt({{ $location->getKey() }})"
                            :label="__('panel.orders.show_orders_here')"
                            :tooltip="__('panel.orders.show_orders_here')"
                        />
                    @endif
                </div>
            </x-filament::section>
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
