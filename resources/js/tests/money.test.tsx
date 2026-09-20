import { render, screen } from '@testing-library/react';
import { act, useState } from 'react';
import { describe, expect, it, vi } from 'vite-plus/test';

import { Money } from '@/components/money';

/**
 * The counting figure.
 *
 * What matters is that the real amount is on screen the moment the component
 * appears — a figure that counted up from zero on first paint would be wrong
 * for the whole of that first journey, and every other test in the suite reads
 * these numbers straight after rendering.
 */
describe('Money', () => {
    it('shows the amount straight away rather than counting up to it', () => {
        render(<Money amount={24950} />);

        expect(screen.getByText(/249\.50/)).toBeInTheDocument();
    });

    it('lands on the new amount when it changes', () => {
        function Changing() {
            const [amount, setAmount] = useState(10000);

            return (
                <>
                    <Money amount={amount} />
                    <button
                        type="button"
                        onClick={() => {
                            setAmount(20000);
                        }}
                    >
                        change
                    </button>
                </>
            );
        }

        // Reduced motion is the one path that is synchronous, so the figure is
        // final by the time this returns. The animated path is the same code
        // with frames in between, and ends on the same number.
        vi.stubGlobal(
            'matchMedia',
            vi.fn(() => ({ matches: true })),
        );

        render(<Changing />);
        expect(screen.getByText(/100\.00/)).toBeInTheDocument();

        act(() => {
            screen.getByRole('button', { name: 'change' }).click();
        });

        expect(screen.getByText(/200\.00/)).toBeInTheDocument();

        vi.unstubAllGlobals();
    });
});
