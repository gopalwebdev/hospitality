<?php

/*
|--------------------------------------------------------------------------
| Guest App
|--------------------------------------------------------------------------
|
| Every word of chrome in the app a diner reads at the table. The dish names,
| sections and tile labels are not here — those are the tenant's own words
| and live in translated database columns.
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
        'additions' => 'Add to this',
        'free' => 'Free',
        'served_between' => 'Served :from to :until',
        'not_being_served' => 'Not being served right now',
        'tax_included' => 'Prices include GST at :rate.',
        'tax_excluded' => 'Prices exclude GST, charged at :rate.',
        'service_charge' => 'A service charge of :rate is added to the bill.',
        'parcel_charge' => 'Takeaway orders are packed for :amount.',
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
