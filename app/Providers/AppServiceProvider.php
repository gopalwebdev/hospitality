<?php

namespace App\Providers;

use App\Exceptions\DuplicateQueryException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\TimePicker;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\DevCommands;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Once;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Every query the current HTTP request has run, keyed by its SQL and
     * bindings, or null while no request is being handled.
     *
     * @var array<string, true>|null
     */
    private ?array $queriesThisRequest = null;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRequestMemoization();
        $this->configureQueryGuards();
        $this->configureAuthorization();
        $this->configureDevProcesses();
        $this->configureTimePickers();
    }

    /**
     * Pick times with Filament's own picker rather than the browser's.
     *
     * The native time input opens a different, unstyled dropdown in every
     * browser, and nothing here — a menu's hours, a tenant's opening times —
     * needs seconds. Set once, so a time picker added later matches the rest.
     */
    protected function configureTimePickers(): void
    {
        TimePicker::configureUsing(static fn (TimePicker $picker): TimePicker => $picker
            ->native(false)
            ->seconds(false)
            ->displayFormat('h:i A')
            ->prefixIcon(Heroicon::OutlinedClock));
    }

    /**
     * Leave the web server out of `php artisan dev`.
     *
     * Herd serves the application at APP_URL and every tenant's subdomain on
     * ports 80 and 443. The built-in server could only add a second copy on
     * another port, where no tenant route matches the host.
     *
     * except() replaces the list rather than adding to it, so Horizon's own
     * exclusion of the queue listener is restated: without it `queue:listen`
     * would run beside Horizon's workers.
     */
    protected function configureDevProcesses(): void
    {
        DevCommands::except('server', 'queue');
    }

    /**
     * Make once() mean once per request rather than once per process.
     *
     * Option lists, a tenant's settings and similar answers are memoized with
     * once() because Filament asks for them several times while it builds and
     * validates one page. Laravel only flushes that cache between tests, so
     * without this a long-lived process — a queue worker, Octane, or a test
     * making several requests — would go on answering from the first request.
     */
    protected function configureRequestMemoization(): void
    {
        Event::listen(RouteMatched::class, static function (): void {
            Once::flush();
        });
    }

    /**
     * Refuse N+1 and duplicate queries while the application is being worked on.
     *
     * Local development and the test suite throw on both, so a lazy load or a
     * query repeated inside one request fails where it was written instead of
     * shipping as a slow page. Nowhere else: an environment someone depends on
     * is never aborted for a performance problem. The suite is included on
     * purpose — it is the one place every page is exercised on every change.
     */
    protected function configureQueryGuards(): void
    {
        $isGuarded = $this->app->environment('local', 'testing');

        // The N+1 (lazy loading), plus reading an attribute that was not
        // selected and silently dropping one that is not fillable.
        Model::shouldBeStrict($isGuarded);

        if ($isGuarded) {
            $this->preventDuplicateQueries();
        }
    }

    /**
     * Throw when one request runs the same query, with the same bindings, twice.
     *
     * Only queries inside an HTTP request count — from the moment its route is
     * matched until its response is handled, Livewire's own requests included.
     * Migrations, seeders, queued jobs, console commands and a test's set-up
     * all run outside that window, where repeating a statement is not a page
     * doing redundant work.
     */
    protected function preventDuplicateQueries(): void
    {
        Event::listen(RouteMatched::class, function (): void {
            $this->queriesThisRequest = [];
        });

        Event::listen(RequestHandled::class, function (): void {
            $this->queriesThisRequest = null;
        });

        DB::listen(function (QueryExecuted $query): void {
            if ($this->queriesThisRequest === null) {
                return;
            }

            // A write changes what the next read will find, so reading the same
            // thing again afterwards is a refresh rather than a repeat — a table
            // redrawn after an action, a relation reloaded after a sync.
            if (! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                $this->queriesThisRequest = [];

                return;
            }

            $signature = $query->connectionName."\0".$query->sql."\0".serialize($query->bindings);

            if (isset($this->queriesThisRequest[$signature])) {
                // A repeat no application code asked for — a package doing its
                // own work twice — is not something this codebase can fix, so
                // it is not something to stop a page for.
                $origin = DuplicateQueryException::applicationOrigin();

                if ($origin !== null) {
                    throw DuplicateQueryException::fromQuery($query, $origin);
                }
            }

            $this->queriesThisRequest[$signature] = true;
        });
    }

    /**
     * Grant the product team every permission.
     *
     * Returning null rather than false leaves every other check to run
     * normally, so this only ever widens access for an admin.
     */
    protected function configureAuthorization(): void
    {
        Gate::before(static fn (User $user): ?bool => $user->isAdmin() ? true : null);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        Vite::prefetch(concurrency: 3);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
