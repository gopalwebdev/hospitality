<x-filament-panels::page>
    {{--
        The menu page's own look, and its drag guard.

        Rows are tinted by what they are, from the classes MenuArrangementTable
        puts on them. While a row is dragged, every row outside the list it
        belongs to is dimmed, and a drop onto one is refused and flashes.
        Inline, because a panel is served Filament's compiled CSS and none of
        ours (.ai/rules/filament.md).

        Filament draws a table's rows as `tr.fi-ta-row`, so the tint sits on the
        row and the coloured edge on its first cell: not every browser draws a
        box-shadow on a table row.
    --}}
    <style>
        .menu-arrangement .fi-ta-row.menu-row {
            transition: opacity 150ms ease, background-color 150ms ease;
        }

        .menu-arrangement .fi-ta-row.menu-row--featured {
            background-color: color-mix(in oklab, var(--warning-500) 10%, transparent);
        }

        .menu-arrangement .fi-ta-row.menu-row--featured > td:first-child {
            box-shadow: inset 4px 0 0 var(--warning-500);
        }

        .menu-arrangement .fi-ta-row.menu-row--combos {
            background-color: color-mix(in oklab, var(--success-500) 10%, transparent);
        }

        .menu-arrangement .fi-ta-row.menu-row--combos > td:first-child {
            box-shadow: inset 4px 0 0 var(--success-500);
        }

        .menu-arrangement .fi-ta-row.menu-row--category {
            background-color: color-mix(in oklab, var(--primary-500) 7%, transparent);
        }

        .menu-arrangement .fi-ta-row.menu-row--category > td:first-child {
            box-shadow: inset 4px 0 0 var(--primary-500);
        }

        .menu-arrangement .fi-ta-row.menu-row--sub_category {
            background-color: color-mix(in oklab, var(--info-500) 4%, transparent);
        }

        .menu-arrangement .fi-ta-row.menu-row--sub_category > td:first-child {
            box-shadow: inset 4px 0 0 color-mix(in oklab, var(--info-500) 60%, transparent);
        }

        /* The tint replaces Filament's own hover colour, so hovering darkens it instead. */
        .menu-arrangement .fi-ta-row.menu-row.fi-clickable:hover {
            background-image: linear-gradient(
                color-mix(in oklab, var(--gray-500) 10%, transparent),
                color-mix(in oklab, var(--gray-500) 10%, transparent)
            );
        }

        .menu-arrangement .fi-ta-row.menu-row--locked {
            opacity: 0.35;
            cursor: not-allowed;
            background-image: repeating-linear-gradient(
                -45deg,
                transparent 0 10px,
                color-mix(in oklab, var(--gray-500) 14%, transparent) 10px 20px
            );
        }

        .menu-arrangement .fi-ta-row.menu-row--refused {
            opacity: 0.8;
            background-color: color-mix(in oklab, var(--danger-500) 14%, transparent);
        }

        .menu-arrangement .fi-ta-row.menu-row--refused > td {
            box-shadow: inset 0 2px 0 var(--danger-500), inset 0 -2px 0 var(--danger-500);
        }
    </style>

    <div class="menu-arrangement">
        {{ $this->table }}
    </div>

    @script
        <script>
            // Filament's drag and drop is SortableJS, reachable as `sortable` on
            // the list element once the table is in reorder mode. It lets a row be
            // dropped anywhere; ApplyMenuArrangement would put it back among its
            // own siblings, but only after the drop, which looked like a bug.
            // This refuses the drop while the row is still being dragged.
            const root = $wire.$el

            const listOf = (row) =>
                [...row.classList]
                    .find((name) => name.startsWith('menu-list--'))
                    ?.slice('menu-list--'.length)

            const guard = (list) => {
                if (list.menuDragGuarded) {
                    return
                }

                // The tables a row opens in its modal are components of their
                // own, each one list that needs no guard.
                if (list.closest('[wire\\:id]') !== root) {
                    list.menuDragGuarded = true

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

                    return reorderWithFilament?.call(this, event)
                })
            }

            const watch = () => root.querySelectorAll('[x-sortable]').forEach(guard)

            new MutationObserver(watch).observe(root, { childList: true, subtree: true })
            watch()
        </script>
    @endscript
</x-filament-panels::page>
