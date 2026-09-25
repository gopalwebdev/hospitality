{{--
    The two ways this page is read, on the project owner's instruction: the
    list of orders, and the floor they are on. One subject, one page, one
    switch — see ListOrders for why the floor stopped being a page of its own.
--}}
<x-filament::tabs contained>
    <x-filament::tabs.item
        :active="! $this->isFloor()"
        icon="heroicon-o-list-bullet"
        wire:click="showLayout('{{ \App\Filament\Tenant\Resources\Orders\Pages\ListOrders::LIST }}')"
    >
        {{ __('panel.orders.layout_list') }}
    </x-filament::tabs.item>

    <x-filament::tabs.item
        :active="$this->isFloor()"
        icon="heroicon-o-squares-2x2"
        wire:click="showLayout('{{ \App\Filament\Tenant\Resources\Orders\Pages\ListOrders::FLOOR }}')"
    >
        {{ __('panel.orders.layout_floor') }}
    </x-filament::tabs.item>
</x-filament::tabs>
