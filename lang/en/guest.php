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
        'add_ons' => 'Add-ons',
        'free' => 'Free',
        'complimentary' => 'Complimentary',
        'served_between' => 'Served :from to :until',
        'not_being_served' => 'Not being served right now',
        'tax_included' => 'Prices include GST at :rate.',
        'tax_excluded' => 'Prices exclude GST, charged at :rate.',
        'charge_rate' => ':name of :rate is added to the bill.',
        'charge_amount' => ':name of :amount is added to the bill.',
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
