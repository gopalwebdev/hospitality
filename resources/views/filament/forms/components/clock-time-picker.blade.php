@php
    use Filament\Support\Icons\Heroicon;

    $statePath = $getStatePath();
    $pickerId = $getId() . '-clock';
    $isDisabled = $isDisabled();
@endphp

{{--
    A time of day as three columns — hour, minute, AM or PM — in Filament's own
    dropdown, so it opens, flips and closes like every other one in the panel.
    The state is "HH:MM" on a 24-hour clock; only what is shown is 12-hour.

    Styled inline, once per page, from Filament's colour variables so dark mode
    follows: a panel ships no utility classes of ours.
--}}
@once
    <style>
        .clock-picker-columns {
            display: flex;
            gap: 0.25rem;
            padding: 0.5rem;
        }

        .clock-picker-column {
            display: flex;
            flex-direction: column;
            gap: 0.125rem;
            height: 13rem;
            overflow-y: auto;
            scrollbar-width: thin;
            padding-inline: 0.125rem;
        }

        .clock-picker-column + .clock-picker-column {
            border-inline-start: 1px solid color-mix(in oklab, var(--gray-500) 20%, transparent);
            padding-inline-start: 0.375rem;
        }

        .clock-picker-option {
            min-width: 2.75rem;
            padding: 0.375rem 0.5rem;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            font-variant-numeric: tabular-nums;
            text-align: center;
            color: inherit;
            cursor: pointer;
        }

        .clock-picker-option:hover {
            background-color: color-mix(in oklab, var(--gray-500) 12%, transparent);
        }

        .clock-picker-option.is-picked {
            background-color: color-mix(in oklab, var(--primary-500) 18%, transparent);
            color: var(--primary-700);
            font-weight: 600;
        }

        .dark .clock-picker-option.is-picked {
            color: var(--primary-400);
        }

        .clock-picker-footer {
            display: flex;
            justify-content: flex-end;
            padding: 0.25rem 0.5rem;
            border-top: 1px solid color-mix(in oklab, var(--gray-500) 20%, transparent);
        }

        .clock-picker-trigger {
            cursor: pointer;
        }
    </style>
@endonce

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        id="{{ $pickerId }}"
        class="clock-picker"
        x-data="{
            state: {{ $applyStateBindingModifiers("\$wire.\$entangle('{$statePath}')") }},
            hours: [12, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11],
            minutes: Array.from({ length: 60 }, (_, minute) => minute),
            picked() {
                if (typeof this.state !== 'string' || ! this.state.includes(':')) {
                    return null;
                }

                const [hour, minute] = this.state.split(':').map(Number);

                return { hour: hour % 12 || 12, minute, pm: hour >= 12 };
            },
            label() {
                const time = this.picked();

                return time === null
                    ? ''
                    : `${time.hour}:${String(time.minute).padStart(2, '0')} ${time.pm ? 'PM' : 'AM'}`;
            },
            pick(change) {
                const time = { hour: 12, minute: 0, pm: false, ...(this.picked() ?? {}), ...change };
                const hour = (time.hour % 12) + (time.pm ? 12 : 0);

                this.state = `${String(hour).padStart(2, '0')}:${String(time.minute).padStart(2, '0')}`;
            },
            reveal() {
                setTimeout(() => {
                    document.getElementById(@js($pickerId))
                        .querySelectorAll('.clock-picker-column')
                        .forEach((column) => {
                            const option = column.querySelector('.is-picked');

                            if (option) {
                                column.scrollTop = option.offsetTop - column.offsetTop - (column.clientHeight - option.clientHeight) / 2;
                            }
                        });
                }, 20);
            },
        }"
        {{ $getExtraAttributeBag() }}
    >
        @if ($isDisabled)
            <x-filament::input.wrapper :disabled="true" :prefix-icon="Heroicon::OutlinedClock">
                <input
                    type="text"
                    readonly
                    disabled
                    id="{{ $getId() }}"
                    class="fi-input"
                    x-bind:value="label()"
                    placeholder="{{ $getPlaceholder() }}"
                />
            </x-filament::input.wrapper>
        @else
            <x-filament::dropdown placement="bottom-start">
                <x-slot name="trigger">
                    <div x-on:mousedown="reveal()">
                        <x-filament::input.wrapper
                            :valid="! $errors->has($statePath)"
                            :prefix-icon="Heroicon::OutlinedClock"
                            class="clock-picker-trigger"
                        >
                            <input
                                type="text"
                                readonly
                                id="{{ $getId() }}"
                                class="fi-input clock-picker-trigger"
                                x-bind:value="label()"
                                placeholder="{{ $getPlaceholder() }}"
                            />
                        </x-filament::input.wrapper>
                    </div>
                </x-slot>

                <div class="clock-picker-columns">
                    <div class="clock-picker-column" role="listbox" aria-label="{{ __('panel.shared.hour') }}">
                        <template x-for="hour in hours" x-bind:key="hour">
                            <button
                                type="button"
                                role="option"
                                class="clock-picker-option"
                                x-bind:class="{ 'is-picked': picked()?.hour === hour }"
                                x-bind:aria-selected="picked()?.hour === hour"
                                x-on:click="pick({ hour })"
                                x-text="hour"
                            ></button>
                        </template>
                    </div>

                    <div class="clock-picker-column" role="listbox" aria-label="{{ __('panel.shared.minute') }}">
                        <template x-for="minute in minutes" x-bind:key="minute">
                            <button
                                type="button"
                                role="option"
                                class="clock-picker-option"
                                x-bind:class="{ 'is-picked': picked()?.minute === minute }"
                                x-bind:aria-selected="picked()?.minute === minute"
                                x-on:click="pick({ minute })"
                                x-text="String(minute).padStart(2, '0')"
                            ></button>
                        </template>
                    </div>

                    <div class="clock-picker-column" role="listbox" aria-label="AM / PM">
                        <button
                            type="button"
                            role="option"
                            class="clock-picker-option"
                            x-bind:class="{ 'is-picked': picked()?.pm === false }"
                            x-bind:aria-selected="picked()?.pm === false"
                            x-on:click="pick({ pm: false })"
                        >
                            AM
                        </button>

                        <button
                            type="button"
                            role="option"
                            class="clock-picker-option"
                            x-bind:class="{ 'is-picked': picked()?.pm === true }"
                            x-bind:aria-selected="picked()?.pm === true"
                            x-on:click="pick({ pm: true })"
                        >
                            PM
                        </button>
                    </div>
                </div>

                <div class="clock-picker-footer">
                    <button type="button" class="clock-picker-option" x-on:click="state = null; close()">
                        {{ __('panel.shared.clear') }}
                    </button>
                </div>
            </x-filament::dropdown>
        @endif
    </div>
</x-dynamic-component>
