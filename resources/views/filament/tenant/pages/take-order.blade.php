<x-filament-panels::page>
    {{--
        The counter: the card on one side, the basket on the other.

        Inline styling, because a panel is served Filament's own compiled CSS
        and none of ours (.ai/rules/filament.md). Anything with a theme —
        buttons, badges, inputs, sections, the details form — is a Filament
        component; what is written here is layout, the tile grid and the diet
        mark, which have no component to borrow.

        Every figure drawn below comes from PriceBasket. Nothing in this file
        adds up money.
    --}}
    <style>
        .to-layout {
            display: grid;
            gap: 1rem;
            align-items: start;
            grid-template-columns: 1fr;
        }

        /* The basket moves beside the card once there is room for both. */
        @media (min-width: 64rem) {
            .to-layout {
                grid-template-columns: minmax(0, 1fr) 22rem;
            }

            .to-basket {
                position: sticky;
                top: 1rem;
            }
        }

        .to-column {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            min-width: 0;
        }

        .to-tiles {
            display: grid;
            gap: 0.5rem;
            grid-template-columns: repeat(auto-fill, minmax(11rem, 1fr));
        }

        .to-tile {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            padding: 0.625rem;
            border-radius: 0.5rem;
            border: 1px solid color-mix(in oklab, var(--gray-500) 25%, transparent);
            background-color: color-mix(in oklab, var(--gray-500) 4%, transparent);
            transition: border-color 120ms ease, background-color 120ms ease;
        }

        .to-tile:hover {
            border-color: color-mix(in oklab, var(--primary-500) 55%, transparent);
            background-color: color-mix(in oklab, var(--primary-500) 7%, transparent);
        }

        .to-tile--held {
            border-color: color-mix(in oklab, var(--primary-500) 70%, transparent);
            background-color: color-mix(in oklab, var(--primary-500) 10%, transparent);
        }

        .to-tile-name {
            display: flex;
            align-items: center;
            gap: 0.375rem;
            font-weight: 600;
            font-size: 0.875rem;
            line-height: 1.35;
        }

        .to-tile-foot {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            margin-top: auto;
            padding-top: 0.375rem;
        }

        .to-price {
            font-weight: 600;
            font-variant-numeric: tabular-nums;
        }

        .to-was {
            text-decoration: line-through;
            font-weight: 400;
            margin-inline-start: 0.25rem;
        }

        .to-muted {
            color: var(--gray-500);
            font-size: 0.75rem;
            line-height: 1.5;
        }

        :is(.dark) .to-muted {
            color: var(--gray-400);
        }

        /* The regulatory square, the same mark the guest app draws. */
        .to-diet {
            width: 0.75rem;
            height: 0.75rem;
            flex: none;
            border-radius: 0.125rem;
            border: 1.5px solid var(--to-diet);
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .to-diet::after {
            content: '';
            width: 0.3125rem;
            height: 0.3125rem;
            border-radius: 9999px;
            background-color: var(--to-diet);
        }

        .to-line {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.5rem;
            padding-block: 0.625rem;
            border-top: 1px solid color-mix(in oklab, var(--gray-500) 20%, transparent);
        }

        .to-line:first-child {
            border-top: 0;
            padding-top: 0;
        }

        .to-line-name {
            font-weight: 500;
            font-size: 0.875rem;
            line-height: 1.35;
        }

        .to-line-right {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 0.375rem;
            flex: none;
        }

        .to-stepper {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
        }

        .to-quantity {
            min-width: 1.5rem;
            text-align: center;
            font-variant-numeric: tabular-nums;
            font-weight: 600;
            font-size: 0.875rem;
        }

        .to-line-total {
            font-variant-numeric: tabular-nums;
            font-weight: 600;
            font-size: 0.875rem;
        }

        .to-totals {
            display: flex;
            flex-direction: column;
            gap: 0.375rem;
            padding-top: 0.75rem;
            border-top: 1px solid color-mix(in oklab, var(--gray-500) 20%, transparent);
        }

        .to-total-row {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 0.75rem;
            font-size: 0.875rem;
            font-variant-numeric: tabular-nums;
        }

        .to-total-row--grand {
            font-size: 1.125rem;
            font-weight: 700;
            padding-top: 0.375rem;
            border-top: 1px solid color-mix(in oklab, var(--gray-500) 20%, transparent);
        }

        .to-place {
            margin-top: 0.75rem;
        }

        .to-sticky-search {
            position: sticky;
            top: 0;
            z-index: 5;
            padding-block: 0.25rem;
        }

        /* A location in the picker: the shared card, made pressable. */
        .to-pick {
            display: block;
            width: 100%;
            text-align: start;
            padding: 0.75rem 0.75rem 0.75rem 1rem;
            border-radius: 0.75rem;
            border: 1px solid color-mix(in oklab, var(--gray-500) 25%, transparent);
            background-color: color-mix(in oklab, var(--gray-500) 4%, transparent);
            cursor: pointer;
            transition: border-color 120ms ease, background-color 120ms ease;
        }

        .to-pick:hover {
            border-color: color-mix(in oklab, var(--primary-500) 60%, transparent);
        }

        .to-pick:focus-visible {
            outline: 2px solid var(--primary-500);
            outline-offset: 2px;
        }

        .to-pick--elsewhere {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            border-style: dashed;
            font-weight: 500;
            min-height: 6rem;
        }

        /* What is already running where this order is going. */
        .to-here {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .to-open-order {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 0.75rem;
            padding-block: 0.5rem;
            border-top: 1px solid color-mix(in oklab, var(--gray-500) 20%, transparent);
            font-size: 0.875rem;
            font-variant-numeric: tabular-nums;
        }
    </style>

    @php $currency = $this->currency(); @endphp

    @if ($this->isPickingLocation())
        @include('filament.tenant.partials.location-card-styles')

        {{--
            The first question, and the reason this is a grid rather than the
            select it used to be: a tenant with fifty rooms is a fifty-row
            dropdown, and staff need to see what is already open at a room
            before they add another order to it.
        --}}
        <x-filament::section
            :heading="__('panel.take_order.where_heading')"
            icon="heroicon-o-map-pin"
        >
            <div style="margin-bottom: 0.75rem;">
                <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
                    <x-filament::input
                        type="search"
                        wire:model.live.debounce.300ms="locationSearch"
                        :placeholder="__('panel.board.search')"
                    />
                </x-filament::input.wrapper>
            </div>

            <div class="lc-grid">
                @foreach ($this->locationCards() as $card)
                    <button
                        type="button"
                        wire:key="pick-{{ $card['location']->getKey() }}"
                        wire:click="chooseLocation({{ $card['location']->getKey() }})"
                        class="lc to-pick lc--{{ $card['activity']['state']->value }}"
                    >
                        @include('filament.tenant.partials.location-card', [
                            'location' => $card['location'],
                            'activity' => $card['activity'],
                            'currency' => $currency,
                        ])
                    </button>
                @endforeach

                {{-- The other path: somewhere that is not one of the rows. --}}
                <button
                    type="button"
                    wire:click="chooseElsewhere"
                    class="to-pick to-pick--elsewhere to-muted"
                >
                    {{ __('panel.take_order.elsewhere') }}
                </button>
            </div>

            @if ($this->locationCards() === [])
                <div class="to-muted" style="margin-top: 0.75rem;">
                    {{ __('panel.board.none_description') }}
                </div>
            @endif
        </x-filament::section>
    @else
    @php
        // Read only once the page is past its first question: reading the card
        // and pricing the basket are several queries the picker has no use for.
        $priced = $this->priced();
        $pricedLines = $this->pricedLines();
        $sections = $this->visibleSections();
        $outsideHours = $this->outsideHours();
        $taxParts = $priced['taxParts'];
        $stateHalf = $priced['isUnionTerritory'] ? 'UTGST' : 'SGST';
    @endphp

    @if ($outsideHours !== [])
        {{--
            Said, not enforced. PlaceOrder is passed allowOutsideHours from this
            page on purpose; every other refusal it makes still stands.
        --}}
        <x-filament::section compact>
            <x-filament::badge color="warning" icon="heroicon-o-clock">
                {{ __('panel.take_order.outside_hours') }}
            </x-filament::badge>

            <div class="to-muted" style="margin-top: 0.375rem;">
                {{ implode(' ', $outsideHours) }}
            </div>
        </x-filament::section>
    @endif

    @if ($this->location() !== null || $this->isElsewhere)
        @include('filament.tenant.partials.location-card-styles')

        @php
            $here = $this->location();
            $activityHere = $this->activityHere();
            $openHere = $this->openOrdersHere();
        @endphp

        {{--
            Where this one is going, and what is already running there —
            the answer to "what is going on at 204" that the grid card only
            summarises. Placed and still owing, the same pair the board counts.
        --}}
        <x-filament::section compact class="lc lc--{{ $activityHere['state']->value }}">
            <div class="to-here">
                <div>
                    <div class="lc-name">
                        {{ $here?->name ?? __('panel.take_order.elsewhere') }}
                    </div>

                    @if ($here !== null)
                        <div class="lc-muted">
                            {{ $here->kind->label() }}@if (filled($here->code)) · {{ $here->code }}@endif
                        </div>
                    @endif
                </div>

                <div class="to-here">
                    @if ($here !== null)
                        <x-filament::badge :color="$activityHere['state']->color()">
                            {{ $activityHere['state']->label() }}
                        </x-filament::badge>

                        @if ($activityHere['outstanding'] > 0)
                            <span class="lc-owing">{{ $currency->format($activityHere['outstanding']) }}</span>
                        @endif
                    @endif

                    @if ($this->hasAnyLocation())
                        <x-filament::button
                            size="sm"
                            color="gray"
                            icon="heroicon-o-arrows-right-left"
                            wire:click="changeLocation"
                        >
                            {{ __('panel.take_order.change_location') }}
                        </x-filament::button>
                    @endif
                </div>
            </div>

            @if ($openHere->isNotEmpty())
                <div style="margin-top: 0.5rem;">
                    <div class="lc-muted">{{ __('panel.take_order.already_here') }}</div>

                    @foreach ($openHere as $open)
                        <div class="to-open-order" wire:key="open-{{ $open->getKey() }}">
                            <span>
                                {{-- The orders list's own modal, so reading one never leaves the counter. --}}
                                {{ ($this->viewOrderAction)(['order' => $open->getKey()]) }}
                                <span class="lc-muted">{{ $open->created_at?->diffForHumans(short: true) }}</span>
                            </span>

                            <span>
                                {{ $currency->format($open->total) }}
                                <span class="lc-muted">
                                    {{ __('panel.take_order.owing', ['amount' => $currency->format($open->amountOutstanding())]) }}
                                </span>
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    @endif

    <div class="to-layout">
        <div class="to-column">
            <div class="to-sticky-search">
                <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
                    <x-filament::input
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        :placeholder="__('panel.take_order.search')"
                    />
                </x-filament::input.wrapper>
            </div>

            @forelse ($sections as $section)
                <x-filament::section :heading="$section['name']" compact>
                    <div class="to-tiles">
                        @foreach ($section['tiles'] as $tile)
                            @php
                                $thing = $tile['model'];
                                $held = $this->heldCount($tile['type'], $thing->getKey());
                                $atLimit = $this->isAtLimit($thing, $tile['type']);
                                $diet = $this->isItem($tile) ? $thing->dietMark() : null;
                            @endphp

                            <div @class(['to-tile', 'to-tile--held' => $held > 0])>
                                <div class="to-tile-name">
                                    @if ($diet !== null)
                                        <span
                                            class="to-diet"
                                            style="--to-diet: var(--{{ $diet->color() }}-500)"
                                            role="img"
                                            aria-label="{{ $diet->label() }}"
                                            title="{{ $diet->label() }}"
                                        ></span>
                                    @endif

                                    <span>{{ $thing->name }}</span>
                                </div>

                                @if (filled($thing->description))
                                    <div class="to-muted">{{ \Illuminate\Support\Str::limit($thing->description, 60) }}</div>
                                @endif

                                <div class="to-tile-foot">
                                    <span class="to-price">
                                        @if ($thing->price === 0)
                                            <span class="to-muted">{{ __('panel.take_order.complimentary') }}</span>
                                        @else
                                            {{ $currency->format($thing->price) }}
                                        @endif

                                        @if ($thing->original_price !== null)
                                            <span class="to-muted to-was">{{ $currency->format($thing->original_price) }}</span>
                                        @endif
                                    </span>

                                    @if ($atLimit)
                                        {{-- Greyed rather than hidden: a missing button reads as sold out. --}}
                                        <x-filament::badge color="gray" size="xs">
                                            {{ __('panel.take_order.limit_reached') }}
                                        </x-filament::badge>
                                    @elseif ($tile['groupLinks'] !== [])
                                        {{ ($this->customiseAction)(['item' => $thing->getKey()]) }}
                                    @else
                                        <x-filament::button
                                            size="xs"
                                            icon="heroicon-o-plus"
                                            wire:click="addTile('{{ $tile['type'] }}', {{ $thing->getKey() }})"
                                            wire:loading.attr="disabled"
                                        >
                                            {{ __('panel.take_order.add') }}
                                        </x-filament::button>
                                    @endif
                                </div>

                                @if ($held > 0)
                                    <div class="to-muted">{{ __('panel.take_order.in_basket', ['count' => $held]) }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </x-filament::section>
            @empty
                <x-filament::section>
                    <x-filament::empty-state
                        :heading="__('panel.take_order.nothing_heading')"
                        :description="__('panel.take_order.nothing_description')"
                        icon="heroicon-o-book-open"
                        :contained="false"
                    />
                </x-filament::section>
            @endforelse
        </div>

        <aside class="to-column to-basket">
            {{ $this->form }}

            <x-filament::section
                :heading="__('panel.take_order.basket')"
                :description="$this->basketCount() > 0 ? trans_choice('panel.take_order.basket_count', $this->basketCount(), ['count' => $this->basketCount()]) : null"
                icon="heroicon-o-shopping-cart"
                compact
            >
                @if ($this->lines === [])
                    <div class="to-muted">{{ __('panel.take_order.basket_empty') }}</div>
                @else
                    @foreach ($this->lines as $line)
                        @php $pricedLine = $pricedLines[$line['key']] ?? null; @endphp

                        <div class="to-line" wire:key="line-{{ $line['key'] }}">
                            <div style="min-width: 0;">
                                <div class="to-line-name">{{ $line['name'] }}</div>

                                @if ($line['choiceNames'] !== [])
                                    <div class="to-muted">{{ implode(' · ', $line['choiceNames']) }}</div>
                                @endif

                                @php $problem = $this->lineProblem($pricedLine); @endphp

                                @if ($problem !== null)
                                    <x-filament::badge color="danger" size="xs">{{ $problem }}</x-filament::badge>
                                @elseif ($pricedLine !== null && $pricedLine['tax'] > 0)
                                    {{-- Per line, because one basket holds a 5% item beside an 18% one. --}}
                                    <div class="to-muted">
                                        {{ __($priced['pricesIncludeTax'] ? 'panel.take_order.tax_included_line' : 'panel.take_order.tax_line', [
                                            'rate' => $this->rate($pricedLine['taxParts']->rate()),
                                            'amount' => $currency->format($pricedLine['tax']),
                                        ]) }}
                                    </div>
                                @endif
                            </div>

                            <div class="to-line-right">
                                <div class="to-stepper">
                                    <x-filament::icon-button
                                        size="xs"
                                        color="gray"
                                        :icon="$line['quantity'] > 1 ? 'heroicon-o-minus' : 'heroicon-o-trash'"
                                        wire:click="decrement('{{ $line['key'] }}')"
                                        :label="__('panel.take_order.fewer')"
                                    />

                                    <span class="to-quantity">{{ $line['quantity'] }}</span>

                                    <x-filament::icon-button
                                        size="xs"
                                        color="gray"
                                        icon="heroicon-o-plus"
                                        wire:click="increment('{{ $line['key'] }}')"
                                        :label="__('panel.take_order.more')"
                                    />
                                </div>

                                <span class="to-line-total">
                                    {{ $currency->format($pricedLine['total'] ?? 0) }}
                                </span>
                            </div>
                        </div>
                    @endforeach

                    <div class="to-totals">
                        <div class="to-total-row">
                            <span>{{ __('panel.orders.subtotal') }}</span>
                            <span>{{ $currency->format($priced['subtotal']) }}</span>
                        </div>

                        @foreach ($priced['charges'] as $charge)
                            <div class="to-total-row">
                                <span>
                                    {{ $charge['name'] }}
                                    @if ($charge['tax'] > 0)
                                        <span class="to-muted">
                                            {{ $this->rate($charge['taxParts']->rate()) }}
                                            {{ __('panel.orders.tax') }}
                                            {{ $currency->format($charge['tax']) }}
                                        </span>
                                    @endif
                                </span>
                                <span>{{ $currency->format($charge['amount']) }}</span>
                            </div>
                        @endforeach

                        @if (! $priced['pricesIncludeTax'] && $priced['tax'] > 0)
                            <div class="to-total-row">
                                <span>{{ __('panel.orders.tax') }}</span>
                                <span>{{ $currency->format($priced['tax']) }}</span>
                            </div>
                        @endif

                        <div class="to-total-row to-total-row--grand">
                            <span>{{ __('panel.orders.total') }}</span>
                            <span>{{ $currency->format($priced['total']) }}</span>
                        </div>

                        @if ($taxParts !== null && $priced['tax'] > 0)
                            {{--
                                The split is drawn here and deliberately not in
                                the guest app: this is the record a tax invoice
                                is raised from (.ai/rules/js.md).
                            --}}
                            <div class="to-muted">
                                @if ($priced['pricesIncludeTax'])
                                    {{ __('panel.take_order.tax_included_total', ['amount' => $currency->format($priced['tax'])]) }} ·
                                @endif
                                CGST {{ $currency->format($taxParts->cgst) }} · {{ $stateHalf }} {{ $currency->format($taxParts->sgst) }}
                            </div>
                        @endif
                    </div>

                    <div class="to-place">
                        <x-filament::button
                            wire:click="placeOrder"
                            wire:loading.attr="disabled"
                            wire:target="placeOrder"
                            icon="heroicon-o-check-circle"
                            size="lg"
                            style="width: 100%; justify-content: center;"
                        >
                            {{ __('panel.take_order.place', ['amount' => $currency->format($priced['total'])]) }}
                        </x-filament::button>

                        <x-filament::link
                            tag="button"
                            color="danger"
                            wire:click="emptyBasket"
                            style="margin-top: 0.5rem;"
                        >
                            {{ __('panel.take_order.empty') }}
                        </x-filament::link>
                    </div>
                @endif
            </x-filament::section>
        </aside>
    </div>
    @endif
</x-filament-panels::page>
