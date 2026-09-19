<?php

/*
|--------------------------------------------------------------------------
| Guest App
|--------------------------------------------------------------------------
|
| Every word of chrome in the app a guest reads at the table or in the room.
| The item names, sections, charge names and tile labels are not here — those
| are the tenant's own words and live in translated database columns.
|
| The whole file is sent to the browser as one Inertia prop, so keep it to what
| the guest app actually renders.
|
*/

return [

    'home' => [
        'title' => 'Welcome',
        'empty' => 'This tenant has not set up its home screen yet. Please ask a member of staff.',
    ],

    'menu' => [
        'title' => 'Menu',
        'empty' => 'This menu is not ready yet. Please ask a member of staff.',
        'featured' => 'Featured',
        'combos' => 'Combos',
        'combo_contains' => 'You get',
        'was' => 'Was :price',
        'save' => 'Save :amount',
        'complimentary' => 'Complimentary',
        'served_between' => 'Served :from to :until',
        'not_being_served' => 'Not being served right now',
        'store_hours' => 'Open :from to :until',
        'store_closed' => 'Closed right now',
        'store_closed_today' => 'Closed today',
        'tax_included' => 'Prices include GST at :rate.',
        'tax_excluded' => 'Prices exclude GST, charged at :rate.',
        'charge_rate' => ':name of :rate is added to the bill.',
        'charge_amount' => ':name of :amount is added to the bill.',
        'add' => 'Add',
        'add_named' => 'Add :name',
        'customisable' => 'Customisable',
    ],

    'customise' => [
        'required' => 'Required',
        'optional' => 'Optional',
        'choose_exactly' => 'Choose :count',
        'choose_up_to' => 'Choose up to :count',
        'choose_at_least' => 'Choose at least :count',
        'choose_any' => 'Choose any',
        'choose_more' => 'Choose :count more from :group',
        'option_up_to' => 'Up to :count',
        'add' => 'Add · :price',
        'quantity' => 'Quantity',
        'increase' => 'One more :name',
        'decrease' => 'One fewer :name',
    ],

    'basket' => [
        'title' => 'Your basket',
        'view' => 'View basket',
        'one_item' => '1 item',
        'items' => ':count items',
        'empty' => 'Nothing in your basket yet.',
        'subtotal' => 'Subtotal',
        'gst' => 'GST',
        'gst_included' => 'Includes GST of :amount',
        'total' => 'Total',
        'remove' => 'Remove :name',
        'unavailable' => 'No longer available',
        'invalid' => 'Choices need changing',
        'pricing' => 'Working out the total…',
        'hint' => 'Show this to a member of staff to place your order.',
        'clear' => 'Empty basket',
    ],

    'orders' => [
        'insufficient_stock' => 'Some things ran out while you were ordering.',
        'store_closed' => 'We are closed right now.',
        'not_being_served' => 'This menu is not being served right now.',
        'lines_changed' => 'Some things in your basket have changed.',
    ],

    'limits' => [
        'up_to' => 'Up to :max per order',
        'reached' => 'Limit reached',
    ],

    'document' => [
        'unavailable' => 'This document could not be opened.',
        'open' => 'Open in a new tab',
    ],

    'status' => [
        'open' => 'Open',
        'closed' => 'Closed',
    ],

    'actions' => [
        'back' => 'Back',
        'switch_to_dark' => 'Switch to dark',
        'switch_to_light' => 'Switch to light',
        'switch_language' => 'Switch language',
    ],

];
