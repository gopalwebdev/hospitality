import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';

import { PreferenceToggles } from '@/components/preference-toggles';
import { initializeTheme } from '@/hooks/use-appearance';

import { stubPageProps } from './page-props';

function stubSystemPreference(prefersDark: boolean): void {
    vi.stubGlobal(
        'matchMedia',
        vi.fn(() => ({
            matches: prefersDark,
            addEventListener: vi.fn(),
            removeEventListener: vi.fn(),
        })),
    );
}

describe('preference toggles', () => {
    beforeEach(() => {
        localStorage.clear();
        document.documentElement.className = '';
        delete document.documentElement.dataset.appearance;
        stubSystemPreference(false);
        initializeTheme();
    });

    it('offers one button for the theme, naming where it goes', () => {
        render(<PreferenceToggles />);

        // The icon shows the destination, not the current state: on a light
        // screen the button offers dark.
        expect(
            screen.getByRole('button', { name: 'Switch to dark' }),
        ).toBeInTheDocument();
    });

    it('turns the app dark and offers the way back', async () => {
        render(<PreferenceToggles />);

        await userEvent.click(
            screen.getByRole('button', { name: 'Switch to dark' }),
        );

        expect(document.documentElement).toHaveClass('dark');
        expect(
            screen.getByRole('button', { name: 'Switch to light' }),
        ).toBeInTheDocument();
    });

    it('remembers the theme so the next visit is painted the same way', async () => {
        render(<PreferenceToggles />);

        await userEvent.click(
            screen.getByRole('button', { name: 'Switch to dark' }),
        );

        expect(localStorage.getItem('appearance')).toBe('dark');
        expect(document.cookie).toContain('appearance=dark');
    });

    it('offers the language the server said is next, by its own name', () => {
        render(<PreferenceToggles />);

        const button = screen.getByRole('button', {
            name: 'Switch language: தமிழ்',
        });

        // Someone looking for Tamil is looking for "தமிழ்", so the label is
        // deliberately not translated into the language being left.
        expect(button).toHaveTextContent('தமிழ்');
    });

    it('submits the language choice to the server rather than switching locally', () => {
        render(<PreferenceToggles />);

        // Half of what a guest reads is translated in the database, so only a
        // round trip can answer in another language.
        const chosen = document.querySelector<HTMLInputElement>(
            'input[name="locale"]',
        );

        expect(chosen).not.toBeNull();
        expect(chosen?.value).toBe('ta');
        expect(chosen?.closest('form')).toHaveAttribute(
            'action',
            expect.stringContaining('/preferences/language'),
        );
    });

    it('offers no language button when there is no tenant to post to', () => {
        stubPageProps({ tenant: null });

        render(<PreferenceToggles />);

        expect(
            screen.queryByRole('button', { name: /switch language/i }),
        ).not.toBeInTheDocument();
        // The theme is a client-side choice, so it survives.
        expect(
            screen.getByRole('button', { name: 'Switch to dark' }),
        ).toBeInTheDocument();
    });
});
