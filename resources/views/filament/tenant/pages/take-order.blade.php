<x-filament-panels::page>
    {{--
        The counter: the card on one side, the basket on the other.

        **Designed at phone width first**, because staff take orders standing
        up. One column on a phone, two from `lg` on a tablet held landscape or
        on a desk. The basket is a bar at the foot of a phone screen and a
        sticky column beside the card on anything wider — the same answer the
        guest app reaches for, and for the same reason: the running total has
        to be readable without scrolling to it.

        Inline styling, because a panel is served Filament's own compiled CSS
        and none of ours (.ai/rules/filament.md). Anything with a theme —
        buttons, badges, inputs, sections, the details form — is a Filament
        component; what is written here is layout, the tile grid, the diet mark
        and the bottom bar, which have no component to borrow. Tap targets are
        thumb-sized, corners are rounded and everything pressable says so.

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

        /* Two columns once there is room; one, stacked, on a phone. */
        @media (min-width: 64rem) {
            .to-layout {
                grid-template-columns: minmax(0, 1fr) 23rem;
            }

            .to-side {
                position: sticky;
                top: 1rem;
                max-height: calc(100vh - 2rem);
                overflow-y: auto;
                overscroll-behavior: contain;
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
            grid-template-columns: repeat(auto-fill, minmax(10.5rem, 1fr));
        }

        .to-tile {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            padding: 0.75rem;
            border-radius: 0.75rem;
            border: 1px solid color-mix(in oklab, var(--gray-500) 22%, transparent);
            background-color: color-mix(in oklab, var(--gray-500) 4%, transparent);
            transition: border-color 120ms ease, background-color 120ms ease, transform 120ms ease;
        }

        .to-tile:hover {
            border-color: color-mix(in oklab, var(--primary-500) 55%, transparent);
            background-color: color-mix(in oklab, var(--primary-500) 7%, transparent);
        }

        .to-tile:active {
            transform: scale(0.99);
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
            padding-top: 0.5rem;
            min-height: 2.25rem;
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
            border-top: 1px solid color-mix(in oklab, var(--gray-500) 18%, transparent);
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
            gap: 0.125rem;
            border-radius: 9999px;
            padding: 0.125rem;
            background-color: color-mix(in oklab, var(--gray-500) 12%, transparent);
        }

        .to-quantity {
            min-width: 1.75rem;
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
            border-top: 1px solid color-mix(in oklab, var(--gray-500) 18%, transparent);
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
            border-top: 1px solid color-mix(in oklab, var(--gray-500) 18%, transparent);
        }

        .to-place {
            margin-top: 0.75rem;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            align-items: stretch;
        }

        .to-block-btn {
            width: 100%;
            justify-content: center;
        }

        /* A location in the picker: the shared card, made pressable. */
        .to-pick {
            display: block;
            width: 100%;
            text-align: start;
            padding: 0.875rem 0.875rem 0.875rem 1.125rem;
            border-radius: 0.75rem;
            border: 1px solid color-mix(in oklab, var(--gray-500) 22%, transparent);
            background-color: color-mix(in oklab, var(--gray-500) 4%, transparent);
            cursor: pointer;
            transition: border-color 120ms ease, background-color 120ms ease, transform 120ms ease;
        }

        .to-pick:hover {
            border-color: color-mix(in oklab, var(--primary-500) 60%, transparent);
        }

        .to-pick:active {
            transform: scale(0.99);
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
            min-height: 6.5rem;
        }

        /* Where this one is going, at the head of the side column. */
        .to-here {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .to-open-order {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            padding-block: 0.5rem;
            border-top: 1px solid color-mix(in oklab, var(--gray-500) 18%, transparent);
            font-size: 0.875rem;
            font-variant-numeric: tabular-nums;
        }

        .to-open-order-actions {
            display: flex;
            align-items: center;
            gap: 0.375rem;
            flex: none;
        }

        /*
            The bar at the foot of a phone screen. It holds the total and the
            one button that matters, so nothing has to be scrolled to before an
            order can be placed. Above `lg` the basket sits beside the card and
            the bar would be saying it twice, so it goes.
        */
        .to-bar {
            position: sticky;
            bottom: 0;
            z-index: 20;
            margin-top: 0.75rem;
            padding: 0.75rem;
            border-radius: 0.875rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            background-color: var(--gray-50);
            border: 1px solid color-mix(in oklab, var(--gray-500) 22%, transparent);
            box-shadow: 0 -4px 16px -8px rgb(0 0 0 / 0.35);
        }

        :is(.dark) .to-bar {
            background-color: var(--gray-900);
        }

        .to-bar-figures {
            display: flex;
            flex-direction: column;
            min-width: 0;
            flex: 1 1 auto;
        }

        .to-bar-total {
            font-size: 1.125rem;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
            line-height: 1.2;
        }

        @media (min-width: 64rem) {
            .to-bar {
                display: none;
            }
        }
    </style>

    @php $currency = $this->currency(); @endphp

    @include('filament.tenant.partials.location-card-styles')

    @if ($this->isPickingLocation())
        {{--
            The first question, and the reason this is a grid rather than the
            select it used to be: a tenant with fifty rooms is a fifty-row
            dropdown, and staff need to see what is already open at a room
            before they add another order to it. The busiest are first
            (ReadFloor), so the likeliest tap is nearest the search box.
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
            // Read only once the page is past its first question: the card and
            // the pricing are several queries the picker has no use for.
            $priced = $this->priced();
            $pricedLines = $this->pricedLines();
            $sections = $this->visibleSections();
            $outsideHours = $this->outsideHours();
            $taxParts = $priced['taxParts'];
            $stateHalf = $priced['isUnionTerritory'] ? 'UTGST' : 'SGST';
            $here = $this->location();
            $activityHere = $this->activityHere();
            $openHere = $this->openOrdersHere();
            $isChanging = $this->isChangingAnOrder();
        @endphp

        @if ($isChanging)
            {{-- Changing an order that already stands, and saying so. --}}
            <x-filament::section compact>
                <x-filament::badge color="warning" icon="heroicon-o-pencil-square">
                    {{ __('panel.take_order.changing', ['number' => $this->orderId]) }}
                </x-filament::badge>

                <div class="to-muted" style="margin-top: 0.375rem;">
                    {{ __('panel.take_order.changing_hint') }}
                </div>
            </x-filament::section>
        @endif

        @if ($outsideHours !== [])
            {{--
                Said, not enforced. PlaceOrder is passed allowOutsideHours from
                this page on purpose; every other refusal it makes still stands.
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

        <div class="to-layout">
            <div class="to-column">
                <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
                    <x-filament::input
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        :placeholder="__('panel.take_order.search')"
                    />
                </x-filament::input.wrapper>

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

                                <div @class(['to-tile', 'to-tile--held' => $held > 0]) wire:key="tile-{{ $tile['key'] }}">
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

                                        @if ($held > 0)
                                            <x-filament::badge size="xs">{{ $held }}</x-filament::badge>
                                        @endif
                                    </div>

                                    @if (filled($thing->description))
                                        <div class="to-muted">{{ \Illuminate\Support\Str::limit($thing->description, 52) }}</div>
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
                                            <x-filament::icon-button
                                                size="md"
                                                icon="heroicon-o-plus"
                                                wire:click="addTile('{{ $tile['type'] }}', {{ $thing->getKey() }})"
                                                wire:loading.attr="disabled"
                                                :label="__('panel.take_order.add')"
                                                :tooltip="__('panel.take_order.add')"
                                            />
                                        @endif
                                    </div>
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

            <aside class="to-column to-side">
                {{--
                    Where this one is going, and what is already running there.
                    In the side column on the project owner's instruction: it
                    was a full-width strip above the card and pushed the whole
                    counter down a screen for something that is context rather
                    than the work.
                --}}
                @if ($here !== null || $this->isElsewhere)
                    <x-filament::section compact class="lc lc--{{ $activityHere['state']->value }}">
                        <div class="to-here">
                            <div style="min-width: 0;">
                                <div class="lc-name">{{ $here?->name ?? __('panel.take_order.elsewhere') }}</div>

                                @if ($here !== null)
                                    <div class="lc-muted">
                                        {{ $here->kind->label() }}@if (filled($here->code)) · {{ $here->code }}@endif
                                        @if ($activityHere['outstanding'] > 0)
                                            · {{ __('panel.take_order.owing', ['amount' => $currency->format($activityHere['outstanding'])]) }}
                                        @endif
                                    </div>
                                @endif
                            </div>

                            @if ($this->hasAnyLocation() && ! $isChanging)
                                <x-filament::icon-button
                                    size="md"
                                    color="gray"
                                    icon="heroicon-o-arrows-right-left"
                                    wire:click="changeLocation"
                                    :label="__('panel.take_order.change_location')"
                                    :tooltip="__('panel.take_order.change_location')"
                                />
                            @endif
                        </div>

                        @if ($openHere->isNotEmpty())
                            <div style="margin-top: 0.5rem;">
                                <div class="lc-muted">{{ __('panel.take_order.already_here') }}</div>

                                @foreach ($openHere as $open)
                                    <div class="to-open-order" wire:key="open-{{ $open->getKey() }}">
                                        <span style="min-width: 0;">
                                            {{ ($this->viewOrderAction)(['order' => $open->getKey()]) }}
                                            <span class="lc-muted">{{ $open->created_at?->diffForHumans(short: true) }}</span>
                                        </span>

                                        <span class="to-open-order-actions">
                                            <x-filament::badge :color="$open->status->color()" size="xs">
                                                {{ $open->status->label() }}
                                            </x-filament::badge>

                                            <span class="to-line-total">{{ $currency->format($open->amountOutstanding()) }}</span>

                                            {{-- Icon buttons: staff at the room work it here rather than going to find it in a list. --}}
                                            {{ ($this->acceptOrderAction)(['order' => $open->getKey()]) }}
                                            {{ ($this->changeOrderAction)(['order' => $open->getKey()]) }}
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </x-filament::section>
                @endif

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
                            @php
                                $pricedLine = $pricedLines[$line['key']] ?? null;
                                $problem = $this->lineProblem($pricedLine);
                            @endphp

                            <div class="to-line" wire:key="line-{{ $line['key'] }}">
                                <div style="min-width: 0;">
                                    <div class="to-line-name">{{ $line['name'] }}</div>

                                    @if ($line['choiceNames'] !== [])
                                        <div class="to-muted">{{ implode(' · ', $line['choiceNames']) }}</div>
                                    @endif

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
                                            size="sm"
                                            color="gray"
                                            :icon="$line['quantity'] > 1 ? 'heroicon-o-minus' : 'heroicon-o-trash'"
                                            wire:click="decrement('{{ $line['key'] }}')"
                                            :label="__('panel.take_order.fewer')"
                                        />

                                        <span class="to-quantity">{{ $line['quantity'] }}</span>

                                        <x-filament::icon-button
                                            size="sm"
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
                                    The split is drawn here and deliberately not
                                    in the guest app: this is the record a tax
                                    invoice is raised from (.ai/rules/js.md).
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
                                :icon="$isChanging ? 'heroicon-o-check' : 'heroicon-o-check-circle'"
                                size="lg"
                                class="to-block-btn"
                            >
                                {{ $isChanging
                                    ? __('panel.take_order.save', ['amount' => $currency->format($priced['total'])])
                                    : __('panel.take_order.place', ['amount' => $currency->format($priced['total'])]) }}
                            </x-filament::button>

                            <x-filament::button
                                color="gray"
                                size="sm"
                                icon="heroicon-o-trash"
                                wire:click="emptyBasket"
                                class="to-block-btn"
                            >
                                {{ __('panel.take_order.empty') }}
                            </x-filament::button>
                        </div>
                    @endif
                </x-filament::section>
            </aside>
        </div>

        {{--
            Phone only: the total and the one button that matters, always
            within a thumb's reach. Hidden from `lg`, where the basket beside
            the card already says both.
        --}}
        @if ($this->lines !== [])
            <div class="to-bar">
                <div class="to-bar-figures">
                    <span class="to-muted">
                        {{ trans_choice('panel.take_order.basket_count', $this->basketCount(), ['count' => $this->basketCount()]) }}
                    </span>
                    <span class="to-bar-total">{{ $currency->format($priced['total']) }}</span>
                </div>

                <x-filament::button
                    wire:click="placeOrder"
                    wire:loading.attr="disabled"
                    wire:target="placeOrder"
                    :icon="$isChanging ? 'heroicon-o-check' : 'heroicon-o-check-circle'"
                    size="lg"
                >
                    {{ $isChanging ? __('panel.take_order.save_short') : __('panel.take_order.place_short') }}
                </x-filament::button>
            </div>
        @endif
    @endif
</x-filament-panels::page>
