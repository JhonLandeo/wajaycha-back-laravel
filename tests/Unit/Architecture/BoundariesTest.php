<?php

declare(strict_types=1);

/**
 * Executable architecture boundaries.
 *
 * The context map and ADR-0005 describe where the seams are. Prose does not fail a
 * build, so until now the only thing holding these lines was whoever happened to
 * review the pull request. These rules put the same statements in CI.
 *
 * Two principles govern what is allowed in this file:
 *
 * 1. **Every rule here passes today.** A rule that starts red is not a boundary, it
 *    is a wish, and a suite that is red on arrival gets skipped within a week. Rules
 *    that would need a large exception list were measured and left out — see the
 *    bottom of this file for what did not make the cut and why.
 *
 * 2. **Exceptions are named, never wildcarded.** An `ignoring()` list with concrete
 *    class names is a debt register: it says what is left and it grows only by
 *    deliberate edit. `ignoring('App\Http')` would say nothing at all.
 *
 * Pest ships architecture testing since v2, so this needs no new dependency —
 * the project's own rule against adding a package for something already at hand.
 */

use Symfony\Component\Finder\Finder;

// ---------------------------------------------------------------- transporte

/**
 * A controller receives a request, calls one thing, returns a response. ADR-0005:
 * "Actions, Imports, Jobs and Controllers orchestrate — they do not decide."
 *
 * Reaching for the DB facade is how a controller stops orchestrating: it becomes the
 * HTTP adapter and the persistence adapter at once, which is the arrangement
 * hexagonal exists to prevent. `DashboardController` held five such calls until
 * `refactor/extract-dashboard-read-model` moved them behind a port.
 *
 * The exception list is gone. It held four controllers when this rule was written and
 * each left by a different route, which is worth recording because the routes are not
 * interchangeable:
 *
 * - `DashboardController` — five raw queries moved behind `DashboardRepositoryContract`.
 * - `TransactionYapeController` — its use case moved to `RegisterYapeImportAction`,
 *   which owns the transaction the controller had been opening.
 * - `PdfController` — deleted. It returned 500 on every request and duplicated logic
 *   the `ProcessPdfImport` job already owned.
 * - `ImportController` — it never called `DB::` at all. It imported the facade and
 *   never used it, so a single dead `use` statement had been holding a whole class on
 *   a debt register.
 * - `DetailsController` — `get_details` moved behind `DetailRepositoryContract`.
 *
 * The rule is now unconditional: no controller in this application may reach for the
 * database. That is a stronger statement than the same rule with a list beside it,
 * and it is only sayable because the list emptied.
 */
arch('a controller does not reach for the database')
    ->expect('App\Http\Controllers')
    ->not->toUse('Illuminate\Support\Facades\DB');

/**
 * A service that types a `Request` cannot be called from a queue worker, a console
 * command or a test without inventing one. It also drags HTTP semantics — query
 * strings, form input, headers — into a place that should only know its own domain.
 *
 * This one holds with no exceptions, which is worth keeping that way.
 */
arch('a domain service does not know about HTTP')
    ->expect('App\Services')
    ->not->toUse('Illuminate\Http\Request');

// ------------------------------------------------------------------ coaching

/**
 * ADR-0009: the coach may only explain figures the system already computed, and the
 * verdict is decided in the domain rather than by whatever prose renders it.
 *
 * `PaceEvaluator` is the load-bearing case — it takes `CategoryMonthSnapshot[]` and
 * returns observations without ever opening a connection, which is what makes the
 * coaching decision testable against a fixed set of transactions. If a future change
 * lets an evaluator query for "one more figure", QA-9 stops being measurable and
 * nothing else in the suite would notice.
 *
 * `SpokenObservationLedger` is excluded deliberately, not reluctantly. It persists
 * which observations were already spoken so a retry cannot repeat itself; it reads no
 * figure that feeds a decision. It is a repository living under a Services namespace,
 * and the exclusion is the honest way to say so until it moves.
 */
arch('the coaching deciders never read the database themselves')
    ->expect('App\Services\Coaching')
    ->not->toUse([
        'Illuminate\Support\Facades\DB',
        'Illuminate\Database\Eloquent\Model',
    ])
    ->ignoring('App\Services\Coaching\SpokenObservationLedger');

/**
 * Raw queries belong to repositories. `DB::transaction()` does not.
 *
 * These are different acts wearing one facade. A query is persistence — it knows a
 * function name, an argument order, a column list. A transaction is a decision about
 * where a consistency boundary falls, which is domain work and belongs wherever that
 * boundary is chosen: `RegisterYapeImportAction` opens one on purpose, and moving it
 * into a repository would hide the very thing it exists to declare.
 *
 * `arch()` cannot express this. It matches imports, and both acts arrive through the
 * same `use Illuminate\Support\Facades\DB`. So the distinction is enforced by reading
 * the source, which is what an architecture rule is allowed to do when the shape of
 * the rule does not fit the helper.
 *
 * `App\Services` reaches this with no exceptions. `FinancialReportService` was the
 * last holdout: it ran `get_monthly_category_budget_report` itself while
 * `CategoryRepository` already owned two other calls to the same function.
 */
it('no service runs a raw query — transactions are allowed, persistence is not', function () {
    // Resolved from this file rather than through `app_path()`: these tests live in
    // tests/Unit, where no application is booted, and an architecture rule has no
    // business needing one to read source.
    $appPath = dirname(__DIR__, 3).'/app';
    $offenders = [];

    foreach (Finder::create()->files()->in($appPath.'/Services')->name('*.php') as $file) {
        // Comments are stripped first. A docblock explaining that a class no longer
        // calls `DB::table()` is not a call, and a rule that cannot tell the difference
        // punishes the documentation it should be encouraging — which is exactly what
        // happened to `UserObserver` when this was measured naively.
        $source = (string) file_get_contents($file->getRealPath());
        $source = (string) preg_replace('!/\*.*?\*/!s', '', $source);
        $source = (string) preg_replace('!//.*$!m', '', $source);

        if (preg_match('/DB::(select|table|raw|statement|insert|update|delete)\b/', $source, $m)) {
            $offenders[] = str_replace($appPath.'/', '', $file->getRealPath()).' → '.$m[0];
        }
    }

    expect($offenders)->toBe([]);
});

// ---------------------------------------------------------------------- DTOs

/**
 * A DTO carrying an Eloquent model is not a data transfer object — it is a lazy
 * database handle with a friendlier name, and it makes every consumer able to
 * trigger a query the caller never asked for. It also defeats the point of the
 * coaching pipeline, where `CategoryMonthSnapshot` exists precisely so that
 * `PaceEvaluator` receives values instead of a live row.
 */
arch('a DTO carries values, not Eloquent models')
    ->expect('App\DTOs')
    ->not->toUse('App\Models');

// -------------------------------------------------------------------- models

/**
 * A model that calls a service inverts the dependency the layering depends on and
 * makes the direction of a change unpredictable: saving a row would run business
 * rules that the caller cannot see and a test cannot isolate.
 */
arch('a model does not call a service')
    ->expect('App\Models')
    ->not->toUse('App\Services');

// --------------------------------------------------------------------- enums

/**
 * An enum is a closed set of named values. `DashboardMeasure` exists so that
 * "count transactions" and "sum amounts" stop travelling as a boolean nobody can
 * read at the call site — it earns nothing if it starts importing collaborators.
 */
arch('an enum depends on nothing')
    ->expect('App\Enums')
    ->toBeEnums()
    ->not->toUse([
        'App\Models',
        'App\Services',
        'App\Repositories',
        'Illuminate\Support\Facades\DB',
    ]);

// ---------------------------------------------------------------- convention

/**
 * `declare(strict_types=1)` is required for new files. Adoption is partial — 70 of
 * 131 files in `app/` at the time of writing — so this is scoped to the namespaces
 * built under the rule rather than asserted across the whole application, which
 * would fail on arrival.
 *
 * Two files predate the rule and are excluded rather than repaired here. Adding the
 * declaration is one line, but it is not inert: under strict types `TransactionDataDTO`
 * stops coercing a numeric string into its `float $amount` and starts throwing. That
 * is a behavioural change in a file this change has no reason to touch, and it would
 * arrive with no test covering the callers it could break. Both belong to whichever
 * change next has business in them.
 */
arch('the newer namespaces declare strict types')
    ->expect(['App\DTOs', 'App\Enums', 'App\Services\Capture', 'App\Services\Coaching'])
    ->toUseStrictTypes()
    ->ignoring([
        'App\DTOs\TransactionDataDTO',
        'App\Enums\VolatilityDiagnostic',
    ]);

/*
|--------------------------------------------------------------------------
| Measured and deliberately not enforced
|--------------------------------------------------------------------------
|
| These were candidates. Each was measured against the codebase and left out,
| recorded here so the next person does not re-derive the same answer.
|
| "Only App\Repositories may use the DB facade."
|     Superseded, and the reason is worth keeping. The original measurement found
|     14 files outside App\Repositories using it and the rule was dropped as
|     unenforceable — but the count was wrong to begin with, because it counted
|     `DB::transaction()` as persistence. It is not: it is a consistency boundary,
|     and the layer that decides one is exactly where it belongs.
|
|     Measured again by act rather than by import, only six places still run a raw
|     query outside a repository: UserObserver, StoreCategoryAction,
|     UpdateCategoryAction, ProcessPdfImport, SmartMergeJob and
|     BackfillSmartDetails. App\Services reached zero and is enforced above.
|     Six is still too many to list as exceptions, but it is a register that is
|     shrinking rather than a number that was never going to move.
|
| "Every DTO is readonly."
|     10 of the DTOs are not. `DashboardScope` is `final readonly` and new ones
|     should be, but the rule cannot be turned on without changing ten classes,
|     and that is a change with its own reasons and its own review.
|
| "A subdomain does not import another subdomain's namespace."
|     The shape everyone wants from a modular monolith, and the one this codebase
|     cannot express yet: `Transaction` appears in 13 files across 9 directories
|     spanning Ingestion, Entity Resolution, Categorization and Financial Analysis.
|     Ask which module owns it and there is no good answer, which is exactly why
|     moving to per-module folders today would produce a `Shared` namespace that
|     swallows the model layer. The boundary has to become true before it can be
|     enforced, and enforcing it first would only make the fiction official.
|
*/
