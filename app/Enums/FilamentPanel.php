<?php

namespace App\Enums;

use Filament\Support\Icons\Heroicon;

/**
 * The Filament panels this application serves, named for whose they are.
 *
 * The backing value is the panel's Filament id, which its route names are built
 * from. Both panels live under /dashboard and are told apart by host: the
 * platform's on the root domain, a tenant's on its own subdomain.
 */
enum FilamentPanel: string
{
    /** The product team's panel, on the root domain. */
    case Platform = 'platform';

    /** One tenant's own panel, on its subdomain, for its owners and staff. */
    case Tenant = 'tenant';

    /**
     * The same for both. That holds only because the platform panel is bound to
     * the root domain and registered first. See bootstrap/providers.php.
     */
    public function path(): string
    {
        return 'dashboard';
    }

    public function icon(): Heroicon
    {
        return match ($this) {
            self::Platform => Heroicon::OutlinedBuildingOffice2,
            self::Tenant => Heroicon::OutlinedBuildingStorefront,
        };
    }

    public function brandName(): string
    {
        return match ($this) {
            self::Platform => 'Hospitality Platform',
            self::Tenant => (string) config('app.name'),
        };
    }

    public function signInDescription(): string
    {
        return match ($this) {
            self::Platform => 'Sign in to manage every tenant on the platform.',
            self::Tenant => 'Sign in to manage your tenant.',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $panel): string => $panel->value,
            self::cases(),
        );
    }
}
