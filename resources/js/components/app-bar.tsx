import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { ChevronLeftIcon } from '@/components/icons';
import { PreferenceToggles } from '@/components/preference-toggles';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';

interface AppBarProps {
    /** The main line: a tenant's name, a menu's name, a document's title. */
    title: string;
    /** A quieter line above it — who is signed in, which tenant. */
    eyebrow?: string;
    /** Where the back arrow goes. Omitted on a screen nobody arrived at from elsewhere. */
    backHref?: string;
    /** Anything the page wants beside the toggles: a badge, a sign-out button. */
    children?: ReactNode;
}

/**
 * The header the guest app wears.
 *
 * Always carries the two things a visitor can change for themselves — light or
 * dark, and the language — because on a phone there is nowhere else to put
 * them: no sidebar, no settings screen worth the taps.
 *
 * There is deliberately no logo. A tenant's name is set and its brand
 * colour is already carried by every button and price on the screen; a logo
 * slot would be an empty box on every tenant that has not uploaded one.
 */
export function AppBar({ title, eyebrow, backHref, children }: AppBarProps) {
    const { t } = useTranslations();

    return (
        <header className="bg-background/95 sticky top-0 z-10 border-b px-3 pt-[max(0.75rem,env(safe-area-inset-top))] pb-3 backdrop-blur">
            <div className="flex items-center gap-1">
                {backHref !== undefined && (
                    <Button
                        asChild
                        variant="ghost"
                        size="icon"
                        aria-label={t('actions.back')}
                    >
                        {/* The screen behind is where most guests go next, so
                            it is fetched as soon as a finger lands on the arrow. */}
                        <Link href={backHref} prefetch={['hover', 'click']}>
                            <ChevronLeftIcon />
                        </Link>
                    </Button>
                )}

                <div
                    className={
                        backHref === undefined
                            ? 'min-w-0 flex-1 pl-2'
                            : 'min-w-0 flex-1'
                    }
                >
                    {eyebrow !== undefined && (
                        <p className="text-muted-foreground truncate text-xs">
                            {eyebrow}
                        </p>
                    )}
                    <h1 className="truncate text-lg leading-tight font-semibold tracking-tight">
                        {title}
                    </h1>
                </div>

                <div className="flex shrink-0 items-center gap-0.5">
                    {children}
                    <PreferenceToggles />
                </div>
            </div>
        </header>
    );
}
