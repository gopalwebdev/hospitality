<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        {{-- viewport-fit=cover so the app reaches under a phone's notch. --}}
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="#E11D48">

        {{-- A table's QR code should not turn up in search results. --}}
        <meta name="robots" content="noindex">

        {{-- Installable, so a guest who comes back keeps the tenant on their
             home screen. The manifest and the worker are served per tenant
             from the root of its subdomain — see ProgressiveWebAppController. --}}
        <link rel="manifest" href="{{ route('guest.manifest', ['tenant' => $tenantSlug]) }}">
        {{-- Both spellings, and both are needed. `mobile-web-app-capable` is
             the standard one, and Chrome logs a deprecation warning without
             it; the `apple-` prefixed one is what iOS read before 11.3 took
             the manifest's `display` instead, and a guest on an older phone
             still installs by it. --}}
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="{{ $theme['name'] }}">

        @include('partials.theme')

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts
        @viteReactRefresh
        {{-- Only this app's entry and only the page being shown: a guest never
             downloads the welcome page's entry, or any of Filament. --}}
        @vite(['resources/css/app.css', 'resources/js/guest.tsx', "resources/js/pages/guest/{$page['component']}.tsx"])

        <x-inertia::head>
            <title>{{ $theme['name'] }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />

        @include('partials.boot-loader')

        <script>
            if ('serviceWorker' in navigator) {
                window.addEventListener('load', function () {
                    navigator.serviceWorker
                        .register(@json(route('guest.service-worker', ['tenant' => $tenantSlug], absolute: false)))
                        .catch(function () {
                            // An app that cannot register a worker still works;
                            // it just cannot be installed. Never block on it.
                        });
                });
            }
        </script>
    </body>
</html>
