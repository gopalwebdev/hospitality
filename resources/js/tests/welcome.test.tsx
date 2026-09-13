import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vite-plus/test';
import Welcome from '@/pages/welcome';

describe('welcome', () => {
    it('leads with what the platform is for', () => {
        render(<Welcome />);

        expect(
            screen.getByRole('heading', {
                level: 1,
                name: 'Run your tenant from one place.',
            }),
        ).toBeInTheDocument();
    });

    it('sends people to the tenant panel with a plain link', () => {
        render(<Welcome />);

        const signIn = screen.getByRole('link', { name: 'Sign in' });

        // The panel is server rendered, so this must be a real navigation
        // rather than an Inertia visit.
        expect(signIn).toHaveAttribute(
            'href',
            expect.stringContaining('/login'),
        );
    });

    it('says sign-in is by one-time code', () => {
        render(<Welcome />);

        expect(screen.getByText('Sign in with a code')).toBeInTheDocument();
    });
});
