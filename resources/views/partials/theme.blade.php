{{--
    Light or dark, decided before anything renders.

    It has to be here rather than in an Inertia prop: React runs after the first
    paint, so a prop would show one shade and then visibly correct itself in
    front of the guest. The value is the visitor's own choice, from the
    unencrypted `appearance` cookie — there is no brand colour and no per
    tenant default. See App\Enums\Appearance.
--}}
<script>
    (function () {
        const appearance = @json($theme['appearance']);

        if (appearance === 'dark') {
            document.documentElement.classList.add('dark');
        }

        // Left on the element for React to read back, so the theme toggle
        // starts in the state the page was actually painted in rather than
        // guessing and then correcting itself.
        document.documentElement.dataset.appearance = appearance;
    })();
</script>
