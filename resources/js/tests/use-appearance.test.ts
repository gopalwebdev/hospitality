import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vite-plus/test';

import { initializeTheme, useAppearance } from '@/hooks/use-appearance';

/**
 * Stand in for what the server painted the page with.
 *
 * partials/theme.blade.php writes this onto the root element from the
 * visitor's own cookie. There is no per tenant default to resolve against
 * — light and dark are the whole of the theming.
 */
function stubServerAppearance(appearance: string | null): void {
    if (appearance === null) {
        delete document.documentElement.dataset.appearance;

        return;
    }

    document.documentElement.dataset.appearance = appearance;
}

/**
 * The value of the appearance cookie, or an empty string when it has none.
 *
 * Clearing a cookie leaves its name behind with no value, so the name alone
 * does not tell you whether anything was stored.
 */
function storedAppearanceCookie(): string {
    return (
        document.cookie
            .split('; ')
            .find((entry) => entry.startsWith('appearance='))
            ?.slice('appearance='.length) ?? ''
    );
}

describe('use-appearance', () => {
    beforeEach(() => {
        localStorage.clear();
        document.documentElement.className = '';
        document.documentElement.style.colorScheme = '';
        document.cookie = 'appearance=;max-age=0;path=/';
        stubServerAppearance(null);
    });

    it('adopts what the server painted the page with', () => {
        stubServerAppearance('dark');

        initializeTheme();

        const { result } = renderHook(() => useAppearance());

        expect(result.current.appearance).toBe('dark');
        expect(document.documentElement).toHaveClass('dark');
    });

    it('stores nothing until the visitor actually chooses', () => {
        stubServerAppearance('dark');

        initializeTheme();

        expect(localStorage.getItem('appearance')).toBeNull();
        expect(storedAppearanceCookie()).toBe('');
    });

    it('falls back to light when the server said nothing', () => {
        initializeTheme();

        const { result } = renderHook(() => useAppearance());

        expect(result.current.appearance).toBe('light');
        expect(document.documentElement).not.toHaveClass('dark');
    });

    it('flips between light and dark', () => {
        initializeTheme();

        const { result } = renderHook(() => useAppearance());

        act(() => {
            result.current.toggleAppearance();
        });

        expect(result.current.appearance).toBe('dark');
        expect(document.documentElement).toHaveClass('dark');

        act(() => {
            result.current.toggleAppearance();
        });

        expect(result.current.appearance).toBe('light');
        expect(document.documentElement).not.toHaveClass('dark');
    });

    it('remembers the chosen mode for the next visit', () => {
        initializeTheme();

        const { result } = renderHook(() => useAppearance());

        act(() => {
            result.current.toggleAppearance();
        });

        expect(localStorage.getItem('appearance')).toBe('dark');
        // The cookie is what lets the server paint the next first response the
        // same way, before React has run.
        expect(storedAppearanceCookie()).toBe('dark');
        expect(document.documentElement.style.colorScheme).toBe('dark');
    });

    it('prefers what the visitor chose over what the server painted', () => {
        localStorage.setItem('appearance', 'dark');
        stubServerAppearance('light');

        initializeTheme();

        const { result } = renderHook(() => useAppearance());

        expect(result.current.appearance).toBe('dark');
        expect(document.documentElement).toHaveClass('dark');
    });

    it('ignores a stored value that is not a mode', () => {
        localStorage.setItem('appearance', 'neon');
        stubServerAppearance('dark');

        initializeTheme();

        const { result } = renderHook(() => useAppearance());

        expect(result.current.appearance).toBe('dark');
    });

    it('tells every subscriber about a change', () => {
        initializeTheme();

        const first = renderHook(() => useAppearance());
        const second = renderHook(() => useAppearance());

        act(() => {
            first.result.current.toggleAppearance();
        });

        expect(second.result.current.appearance).toBe('dark');
    });
});
