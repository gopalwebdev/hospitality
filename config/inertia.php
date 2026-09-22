<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Server Side Rendering
    |--------------------------------------------------------------------------
    |
    | Off: the app is rendered in the browser and nowhere else. Inertia's
    | Vite plugin pre-renders through the dev server whenever Vite is
    | running hot, and nothing has ever pre-rendered a deployed page —
    | there is no bundle under bootstrap/ssr and no inertia:start-ssr
    | process — so leaving this on meant a page server rendered while it
    | was being written and client rendered once it shipped.
    |
    | See: https://inertiajs.com/server-side-rendering
    |
    */

    'ssr' => [

        'enabled' => false,

    ],

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | These options configure how Inertia discovers page components on the
    | filesystem. The paths and extensions are used to locate components
    | when rendering responses and during testing assertions.
    |
    */

    'pages' => [

        // The guest entry is scoped to pages/guest by the `pages` option in
        // resources/js/guest.tsx, so its component names are relative to that
        // directory — 'menu', not 'guest/menu'. The root domain's welcome page
        // sits in js/pages itself.
        'paths' => [
            resource_path('js/pages'),
            resource_path('js/pages/guest'),
        ],

        'extensions' => [
            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Testing
    |--------------------------------------------------------------------------
    |
    | The values described here are used to locate Inertia components on the
    | filesystem. For instance, when using `assertInertia`, the assertion
    | attempts to locate the component as a file relative to the paths.
    |
    */

    'testing' => [

        'ensure_pages_exist' => true,

    ],

];
