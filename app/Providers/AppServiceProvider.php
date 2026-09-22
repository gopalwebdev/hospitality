<?php

namespace App\Providers;

use App\Exceptions\DuplicateQueryException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
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
    /** How a date reads wherever one is shown: "Aug 6, 2025". */
    public const string DATE_FORMAT = 'M j, Y';

    /** How a date and a time read together: "Aug 6, 2025 8:37 AM". */
    public const string DATE_TIME_FORMAT = 'M j, Y g:i A';

    /** How a time of day reads on its own: "8:37 AM". */
    public const string TIME_FORMAT = 'g:i A';

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
        $this->configureDateTimeDisplay();
        $this->configureTables();
        $this->configureRequestMemoization();
        $this->configureQueryGuards();
        $this->configureAuthorization();
        $this->configureDevProcesses();
    }

    /**
     * How every table in both panels behaves, set once rather than per table.
     *
     * All of it is Filament's own settings, on the project owner's instruction:
     * **do not hand-roll table chrome**, configure what Filament already offers.
     *
     * - `hiddenFilterIndicators()` drops the "Active filters" strip above the
     *   rows. It repeated what the controls beside it already said — the search
     *   box shows its own term with its own clear button, and the filter button
     *   carries a count badge — so a search read as a filter and a filter read
     *   twice. What a page is narrowed to is now read where it is set.
     * - The two `persist...InSession()` calls keep a search and a set of filters
     *   across a visit, so opening a record and coming back does not drop what
     *   was being looked at.
     *
     * A table that needs different behaviour still overrides it at its own call
     * site; this is only the default.
     */
    protected function configureTables(): void
    {
        Table::configureUsing(static function (Table $table): void {
            $table
                ->hiddenFilterIndicators()
                ->persistFiltersInSession()
                ->persistSearchInSession();
        });
    }

    /**
     * One date format and one time format, across both panels.
     *
     * Filament's own defaults are `M j, Y H:i:s` and `H:i:s` — a 24-hour clock
     * carrying seconds, which is neither how a time is read here nor a
     * precision anything in either panel needs. `Table` and `Schema` each hold
     * the defaults every `date()`, `dateTime()` and `time()` column and entry
     * falls back to, so setting them once here is what stops a dozen call
     * sites each naming a format of its own and drifting apart. A call site
     * that passes its own format still wins.
     *
     * The guest app formats its own times in the browser, in the reader's
     * language — see `resources/js/lib/time.ts`.
     */
    protected function configureDateTimeDisplay(): void
    {
        Table::configureUsing(static function (Table $table): void {
            $table
                ->defaultDateDisplayFormat(self::DATE_FORMAT)
                ->defaultDateTimeDisplayFormat(self::DATE_TIME_FORMAT)
                ->defaultTimeDisplayFormat(self::TIME_FORMAT);
        });

        Schema::configureUsing(static function (Schema $schema): void {
            $schema
                ->defaultDateDisplayFormat(self::DATE_FORMAT)
                ->defaultDateTimeDisplayFormat(self::DATE_TIME_FORMAT)
                ->defaultTimeDisplayFormat(self::TIME_FORMAT);
        });
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
