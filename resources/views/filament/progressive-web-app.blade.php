{{--
    Installable, so a panel worked in all day opens in a window of its own.

    The manifest and the worker live under the panel's path, never at the root
    of the host: a tenant's subdomain also serves the guest app, whose worker
    is scoped to the root. See PanelProgressiveWebAppController.
--}}
<link rel="manifest" href="{{ $manifestUrl }}">
<meta name="theme-color" content="{{ $panel->themeColor() }}">

<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker
                .register(@json($serviceWorkerUrl), { scope: @json('/'.$panel->path()) })
                .catch(function () {
                    // A panel that cannot register a worker still works; it
                    // just cannot be installed. Never block on it.
                });
        });
    }
</script>
