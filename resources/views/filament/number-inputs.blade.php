{{--
    A number in a panel is never negative — a price, a rate, a count — and the
    browser's up/down arrows inside the box are noise beside a typed amount.

    The CSS hides the arrows. One listener on the page refuses the keys that
    would make a number negative or exponential, a pasted one, and the mouse
    wheel turning a focused number. Every such field also carries minValue(),
    which is what the server refuses on; this only stops the keystroke.
--}}
<style>
    input[type='number']::-webkit-inner-spin-button,
    input[type='number']::-webkit-outer-spin-button {
        -webkit-appearance: none;
        margin: 0;
    }

    input[type='number'] {
        -moz-appearance: textfield;
        appearance: textfield;
    }
</style>

<script>
    (() => {
        const isNumber = (target) => target instanceof HTMLInputElement && target.type === 'number';

        document.addEventListener('keydown', (event) => {
            if (isNumber(event.target) && ['-', '+', 'e', 'E'].includes(event.key)) {
                event.preventDefault();
            }
        });

        document.addEventListener('paste', (event) => {
            if (isNumber(event.target) && /[-+eE]/.test(event.clipboardData?.getData('text') ?? '')) {
                event.preventDefault();
            }
        });

        document.addEventListener('wheel', (event) => {
            if (isNumber(event.target) && document.activeElement === event.target) {
                event.target.blur();
            }
        }, { passive: true });
    })();
</script>
