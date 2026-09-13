import '@testing-library/jest-dom/vitest';

import { cleanup } from '@testing-library/react';
import { afterEach, vi } from 'vite-plus/test';

import { resetPageProps, readPageProps } from './page-props';

// Inertia's <Head> reaches for a head manager that only exists once
// createInertiaApp has run. These tests render pages on their own, and nothing
// a page puts in the document head is what they are checking.
//
// usePage() is the other half: every page of both phone apps reads the shared
// props — the tenant, the language, the chrome strings — and outside
// createInertiaApp there is nowhere for those to come from. Tests set them with
// stubPageProps(); see page-props.ts.
vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    usePage: () => ({ props: readPageProps() }),
}));

// React Testing Library leaves its container in the document; without this the
// next test in the file would render into a page that still holds the last one.
afterEach(() => {
    cleanup();
    resetPageProps();
});
