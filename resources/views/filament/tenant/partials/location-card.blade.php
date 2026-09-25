{{--
    What one location reads as: its name, what kind of place it is, and what is
    open there right now.

    Takes $location, $activity (one entry of App\Actions\Orders\ReadLocationActivity)
    and $currency. The wrapper is the including page's business — the orders
    page's floor layout wraps it in a section with actions, the counter's
    picker in a button — so that only what would otherwise drift lives here.

    **A quiet location says one thing and no more.** It carried a "Clear" badge
    and a ₹0.00 beside it, and the project owner had both taken out: a badge
    that is on every card says nothing, and a total of nothing is not a total.
    So the badge and the amount appear only once something is actually open,
    which is also what makes the open ones findable at a glance.
--}}
<div class="lc-head">
    <div>
        <div class="lc-name">{{ $location->name }}</div>
        <div class="lc-muted">
            {{ $location->kind->label() }}@if (filled($location->code)) · {{ $location->code }}@endif
            @if ($location->capacity !== null)
                · {{ trans_choice('panel.board.capacity', $location->capacity, ['count' => $location->capacity]) }}
            @endif
        </div>
    </div>

    @if ($activity['state']->isOpen())
        <x-filament::badge :color="$activity['state']->color()">
            {{ $activity['state']->label() }}
        </x-filament::badge>
    @endif
</div>

<div class="lc-figures">
    @if ($activity['state']->isOpen())
        <span class="lc-owing">{{ $currency->format($activity['outstanding']) }}</span>

        <span class="lc-muted">
            {{ trans_choice('panel.board.orders_open', $activity['openOrders'], ['count' => $activity['openOrders']]) }}
            @if ($activity['lastOrderedAt'] !== null)
                · {{ $activity['lastOrderedAt']->diffForHumans(short: true) }}
            @endif
        </span>
    @else
        <span class="lc-muted">{{ __('panel.board.nothing_open') }}</span>
    @endif
</div>
