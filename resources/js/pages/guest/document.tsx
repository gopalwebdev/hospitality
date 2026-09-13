import { Head, usePage } from '@inertiajs/react';

import { AppBar } from '@/components/app-bar';
import { useTranslations } from '@/hooks/use-translations';
import { home } from '@/routes/guest';
import type { TenantSharedProps } from '@/types';

interface DocumentProps {
    title: string;
    documentUrl: string;
}

/**
 * The PDF behind a tile, shown inside the app.
 *
 * Embedded rather than opened in the browser's own viewer, which is the whole
 * reason this page exists: a guest who followed a link out of the app has no
 * way back to the menu but the phone's own gesture, and half of them will not
 * find it. Here the app keeps its header and its back arrow.
 *
 * The link out is still offered underneath, because an embedded PDF is
 * genuinely awkward on some phones and a guest who wants the real viewer
 * should be able to say so.
 */
export default function Document({ title, documentUrl }: DocumentProps) {
    const { tenant } = usePage<TenantSharedProps>().props;
    const { t } = useTranslations();

    return (
        <>
            <Head title={title} />

            <AppBar
                title={title}
                eyebrow={tenant?.name}
                backHref={tenant === null ? undefined : home.url(tenant.slug)}
            />

            <main className="flex flex-1 flex-col">
                <iframe
                    src={documentUrl}
                    title={title}
                    className="w-full flex-1 border-0"
                />

                <div className="border-t px-5 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
                    <a
                        href={documentUrl}
                        target="_blank"
                        rel="noreferrer"
                        className="text-primary text-sm font-medium underline-offset-4 hover:underline"
                    >
                        {t('document.open')}
                    </a>
                </div>
            </main>
        </>
    );
}
