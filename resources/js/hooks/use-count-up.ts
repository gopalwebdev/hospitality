import { useEffect, useRef, useState } from 'react';

/**
 * How long a number takes to travel from its old value to its new one.
 *
 * Long enough to read as movement, short enough that a guest tapping a stepper
 * twice does not watch the first journey finish before the second starts.
 */
const DURATION_MS = 420;

/** Whether this guest has asked their device for less movement. */
function wantsLessMotion(): boolean {
    return (
        typeof window !== 'undefined' &&
        typeof window.matchMedia === 'function' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
    );
}

/**
 * A number that travels to its new value rather than jumping to it.
 *
 * Money on this screen is the server's answer, and a new answer arrives every
 * time the basket changes. Swapping one total for another in a single frame
 * reads as a flicker — the eye catches that something changed but not what —
 * so the figure counts across instead, which is legible and tells the guest
 * which way it went.
 *
 * Only the number moves. The label beside it never re-renders and nothing is
 * unmounted, so no text flickers.
 *
 * The value is snapped, not travelled, in three cases: the first render, a
 * guest who has asked for reduced motion, and anywhere `requestAnimationFrame`
 * is not available (the test environment, chiefly) — so what is on screen is
 * always the real figure by the time anything reads it.
 */
export function useCountUp(value: number): number {
    const [shown, setShown] = useState(value);

    // What is actually on screen right now. An animation interrupted halfway
    // has to set off from where it got to, not from the value it was aiming at.
    const onScreen = useRef(value);
    const isFirstRender = useRef(true);

    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;
            onScreen.current = value;

            return;
        }

        if (
            onScreen.current === value ||
            wantsLessMotion() ||
            typeof requestAnimationFrame !== 'function'
        ) {
            onScreen.current = value;
            setShown(value);

            return;
        }

        const from = onScreen.current;
        const distance = value - from;
        const startedAt = performance.now();
        let frame = requestAnimationFrame(function step(now: number) {
            const progress = Math.min((now - startedAt) / DURATION_MS, 1);
            // Ease out: quick off the mark, settling onto the final figure.
            const eased = 1 - (1 - progress) ** 3;
            const next = Math.round(from + distance * eased);

            onScreen.current = next;
            setShown(next);

            if (progress < 1) {
                frame = requestAnimationFrame(step);
            }
        });

        return () => {
            cancelAnimationFrame(frame);
        };
    }, [value]);

    return shown;
}
