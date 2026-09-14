<x-filament-panels::page>
    {{--
        The menu page's own look, and its drag guard.

        Rows are tinted by what they are, from the classes MenuArrangementTable
        puts on them. While a row is dragged, every row outside the list it
        belongs to is dimmed, a drop onto one is refused and flashes, and a note
        at the foot of the screen says why. Inline, because a panel is served
        Filament's compiled CSS and none of ours (.ai/rules/filament.md).
    --}}
    <style>
        .menu-arrangement .fi-ta-record.menu-row {
            transition: opacity 150ms ease, background-color 150ms ease;
        }

        .menu-arrangement .fi-ta-record.menu-row--block {
            background-color: color-mix(in oklab, var(--warning-500) 10%, transparent);
            box-shadow: inset 4px 0 0 var(--warning-500);
        }

        .menu-arrangement .fi-ta-record.menu-row--category {
            background-color: color-mix(in oklab, var(--primary-500) 8%, transparent);
            box-shadow: inset 4px 0 0 var(--primary-500);
        }

        .menu-arrangement .fi-ta-record.menu-row--sub_category {
            background-color: color-mix(in oklab, var(--info-500) 6%, transparent);
            box-shadow: inset 4px 0 0 color-mix(in oklab, var(--info-500) 65%, transparent);
        }

        .menu-arrangement .fi-ta-record.menu-row--featured_item,
        .menu-arrangement .fi-ta-record.menu-row--combo {
            box-shadow: inset 4px 0 0 color-mix(in oklab, var(--warning-500) 35%, transparent);
        }

        .menu-arrangement .fi-ta-record.menu-row--locked {
            opacity: 0.35;
            cursor: not-allowed;
            background-image: repeating-linear-gradient(
                -45deg,
                transparent 0 10px,
                color-mix(in oklab, var(--gray-500) 14%, transparent) 10px 20px
            );
        }

        .menu-arrangement .fi-ta-record.menu-row--refused {
            opacity: 0.8;
            background-color: color-mix(in oklab, var(--danger-500) 14%, transparent);
            box-shadow: inset 0 0 0 2px var(--danger-500);
        }

        .menu-drag-hint {
            position: fixed;
            inset-inline: 0;
            bottom: 1.5rem;
            z-index: 40;
            width: max-content;
            max-width: calc(100vw - 2rem);
            margin-inline: auto;
            padding: 0.625rem 1rem;
            border-radius: 9999px;
            background-color: var(--gray-900);
            color: #fff;
            font-size: 0.875rem;
            box-shadow: 0 10px 30px rgb(0 0 0 / 0.25);
        }

        .menu-drag-hint[hidden] {
            display: none;
        }
    </style>

    <div class="menu-arrangement">
        {{ $this->table }}
    </div>

    <div
        class="menu-drag-hint"
        data-menu-drag-hint
        data-hint-item="{{ __('panel.arrangement.drag_hint_item') }}"
        data-hint-sub-category="{{ __('panel.arrangement.drag_hint_sub_category') }}"
        data-hint-top-level="{{ __('panel.arrangement.drag_hint_top_level') }}"
        data-hint-featured-item="{{ __('panel.arrangement.drag_hint_featured_item') }}"
        data-hint-combo="{{ __('panel.arrangement.drag_hint_combo') }}"
        role="status"
        aria-live="polite"
        hidden
    ></div>

    @script
        <script>
            // Filament's drag and drop is SortableJS, reachable as `sortable` on
            // the list element once the table is in reorder mode. It lets a row be
            // dropped anywhere; ApplyMenuArrangement would put it back among its
            // own siblings, but only after the drop, which looked like a bug.
            // This refuses the drop while dragging, and says why.
            const root = $wire.$el
            const hint = root.querySelector('[data-menu-drag-hint]')

            const classSuffix = (row, prefix) =>
                [...row.classList]
                    .find((name) => name.startsWith(prefix) && ! name.endsWith('--locked') && ! name.endsWith('--refused'))
                    ?.slice(prefix.length)

            const listOf = (row) => classSuffix(row, 'menu-list--')

            const hintFor = (row) =>
                ({
                    item: hint.dataset.hintItem,
                    sub_category: hint.dataset.hintSubCategory,
                    category: hint.dataset.hintTopLevel,
                    block: hint.dataset.hintTopLevel,
                    featured_item: hint.dataset.hintFeaturedItem,
                    combo: hint.dataset.hintCombo,
                })[classSuffix(row, 'menu-row--')] ?? ''

            const guard = (list) => {
                if (list.menuDragGuarded) {
                    return
                }

                // Alpine may not have created the Sortable instance yet.
                if (! list.sortable) {
                    requestAnimationFrame(() => guard(list))

                    return
                }

                list.menuDragGuarded = true

                const rows = () => list.querySelectorAll('[x-sortable-item]')
                const reorderWithFilament = list.sortable.option('onEnd')

                list.sortable.option('onStart', (event) => {
                    const own = listOf(event.item)

                    rows().forEach((row) => row.classList.toggle('menu-row--locked', listOf(row) !== own))

                    hint.textContent = hintFor(event.item)
                    hint.hidden = hint.textContent === ''
                })

                list.sortable.option('onMove', (event) => {
                    if (listOf(event.related) === listOf(event.dragged)) {
                        return true
                    }

                    event.related.classList.add('menu-row--refused')
                    setTimeout(() => event.related.classList.remove('menu-row--refused'), 450)

                    return false
                })

                list.sortable.option('onEnd', function (event) {
                    rows().forEach((row) => row.classList.remove('menu-row--locked', 'menu-row--refused'))
                    hint.hidden = true

                    return reorderWithFilament?.call(this, event)
                })
            }

            const watch = () => root.querySelectorAll('[x-sortable]').forEach(guard)

            new MutationObserver(watch).observe(root, { childList: true, subtree: true })
            watch()
        </script>
    @endscript
</x-filament-panels::page>
