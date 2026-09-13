import { Form, usePage } from '@inertiajs/react';

import { LanguagesIcon } from '@/components/icons';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';
import language from '@/routes/preferences/language';
import type { TenantSharedProps } from '@/types';

/**
 * One button, English or Tamil.
 *
 * A real form rather than something the browser does on its own, and
 * deliberately: half of what a guest reads — the item names, the sections, the
 * tile labels — is translated in the database, so only the server can answer in
 * another language. Submitting records the choice in a cookie and sends them
 * back to the page they were on, re-rendered in the language they picked.
 *
 * With two languages this is a toggle. The server works out which one is next,
 * so this button does not need to know the list.
 */
export function LanguageToggle() {
    const { tenant, locale } = usePage<TenantSharedProps>().props;
    const { t } = useTranslations();

    const next = locale.available.find(
        (option) => option.value === locale.next,
    );

    if (tenant === null || next === undefined) {
        return null;
    }

    const label = `${t('actions.switch_language')}: ${next.label}`;

    return (
        <Form action={language.update(tenant.slug)} className="contents">
            <input type="hidden" name="locale" value={next.value} />

            <Button
                type="submit"
                variant="ghost"
                size="sm"
                aria-label={label}
                title={label}
                className="gap-1.5 px-2"
            >
                <LanguagesIcon />
                <span className="text-xs font-semibold">{next.shortLabel}</span>
            </Button>
        </Form>
    );
}
