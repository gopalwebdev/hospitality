import { Head } from '@inertiajs/react';
import { login } from '@/routes/platform';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

/**
 * The marketing page for the platform itself, served from the root domain.
 *
 * Each tenant gets its own subdomain for its storefront, and the same
 * subdomain plus /login for the people who work there.
 */
export default function Welcome() {
    const capabilities = [
        {
            title: 'One subdomain per tenant',
            description:
                'Every tenant gets its own storefront address, and its own admin behind it.',
        },
        {
            title: 'Sign in with a code',
            description:
                'No passwords to lose. Enter an email address and a one-time code arrives to let you in.',
        },
        {
            title: 'Roles that fit a kitchen',
            description:
                'Owners, managers and floor staff each see only the part of the dashboard they need.',
        },
    ];

    return (
        <>
            <Head title="Tenant Platform" />

            <div className="bg-background text-foreground min-h-screen">
                <header className="border-b">
                    <div className="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-4 px-6 py-6">
                        <span className="text-lg font-semibold tracking-tight">
                            Tenant Platform
                        </span>

                        {/* The tenant panel is server rendered, so this leaves
                            the Inertia app rather than navigating inside it. */}
                        <a
                            href={login.url()}
                            className="border-input hover:bg-accent hover:text-accent-foreground inline-flex items-center rounded-md border px-4 py-2 text-sm font-medium transition-colors"
                        >
                            Sign in
                        </a>
                    </div>
                </header>

                <main className="mx-auto max-w-5xl px-6 py-16">
                    <div className="max-w-2xl space-y-4">
                        <Badge variant="secondary">In development</Badge>

                        <h1 className="text-4xl font-semibold tracking-tight">
                            Run your tenant from one place.
                        </h1>

                        <p className="text-muted-foreground text-lg">
                            Menus, offers and orders for every tenant on the
                            platform, each on its own subdomain.
                        </p>
                    </div>

                    <div className="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        {capabilities.map((capability) => (
                            <Card key={capability.title}>
                                <CardHeader>
                                    <CardTitle>{capability.title}</CardTitle>
                                    <CardDescription>
                                        {capability.description}
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <p className="text-muted-foreground text-sm">
                                        Not built yet.
                                    </p>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                </main>
            </div>
        </>
    );
}
