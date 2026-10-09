# Tasks — Weekly grocery planning

- **Change:** `grocery-planning`
- **Date:** 2026-09-26
- **Spec:** [`specs/shopping/spec.md`](specs/shopping/spec.md) · **Design:** [`design.md`](design.md)
- **Scope:** Backend only (`wajaycha-back-laravel`). Frontend is deferred — see `state.yaml:frontend_scope`.
- **Strict TDD:** enabled (`openspec/config.yaml:64`). Every behavioural task pairs a failing test with its implementation.
- **Test command:** `php artisan test` (real PostgreSQL `wajaycha-1`, `RefreshDatabase` on Feature only) · **Static analysis:** `./vendor/bin/phpstan analyse` (Larastan level 6) · **Lint:** `./vendor/bin/pint --test`

## Review Workload Forecast

| Field | Value |
|---|---|
| Estimated changed lines | ≈ 1,540 (backend only, five slices) |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | Slice 1 → Slice 2 → Slice 3 → Slice 4 → Slice 5 |
| Delivery strategy | ask-on-risk |
| Chain strategy | stacked-to-main (cached; not re-asked, per the `financial-coaching-clarify` precedent) |

```text
Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High
```

| # | Slice | Est. lines | Note |
|---|---|---|---|
| 1 | `products` + `Unit` enum + subdomain/doc registration | ~380 | Closest to the 400 ceiling — migration, model, factory, enum, seeder, ~40-item catalogue config, two endpoints, two FormRequests, three doc/config edits and their tests. Likely to tip over 400 once written |
| 2 | `pantry_items` + `AcquisitionSource` + CRUD | ~320 | Pays for the composed unit/visibility `Rule::exists` and the no-route-model-binding pattern, reused by slice 3 |
| 3 | `consumption_habits` + CRUD | ~240 | Reuses slice 2's patterns verbatim; the smallest slice |
| 4 | `grocery_budget_links` + lazy-resolve-then-pin + composed ceiling read | ~300 | Independently valuable and verifiable, not independently user-visible (ships only the link endpoints) |
| 5 | Pure weekly-list builder + owning `ShoppingPlanService` + endpoint | ~300 | No new migration; the only slice with `tests/Unit/` coverage |

Re-forecast against this concrete task list, not inherited from `proposal.md`'s ~1,490 or
`design.md`'s six-slice ~1,940 (both include the now-deferred frontend or a different slice
split). Every individual slice estimate stays at or under ~380 lines, but slice 1 is tight
enough, and the aggregate large enough, that stacked commits stay the safer default.

### Suggested Work Units

| Unit | Goal | Commit | Focused test command | Runtime harness | Rollback boundary |
|---|---|---|---|---|---|
| 1 | `products` catalogue + `Unit` enum + subdomain/doc registration | Commit 1 | `php artisan test --filter=ShoppingSchemaTest`, `--filter=ProductCatalogueSeederTest`, `--filter=ProductCatalogueEndpointTest` | `php artisan db:seed --class=ProductCatalogueSeeder` against `wajaycha-1`, then `GET /api/shopping/products` with a real JWT | `DROP TABLE products`; revert the commit — enum, config, seeder, docs and `config.yaml` edits are code/text only |
| 2 | `pantry_items` + `AcquisitionSource` + CRUD | Commit 2 | `php artisan test --filter=PantryItemEndpointTest`, `--filter=ShoppingSchemaTest` | `POST/GET/PUT/DELETE /api/shopping/pantry-items` against `wajaycha-1` with a real JWT | `DROP TABLE pantry_items`; `products` unaffected; revert the commit |
| 3 | `consumption_habits` + CRUD | Commit 3 | `php artisan test --filter=ConsumptionHabitEndpointTest`, `--filter=ShoppingSchemaTest` | `POST/GET/PUT/DELETE /api/shopping/consumption-habits` against `wajaycha-1` | `DROP TABLE consumption_habits`; revert the commit |
| 4 | `grocery_budget_links` + lazy-resolve-then-pin + composed ceiling read | Commit 4 | `php artisan test --filter=GroceryBudgetLink`, `--filter=GroceryCeilingReadTest`, `--filter=GroceryCategoryResolutionTest` | `GET`/`PUT /api/shopping/grocery-budget-link` against `wajaycha-1`; delete the pinned category and confirm cascade | `DROP TABLE grocery_budget_links`; `categories` untouched; revert the commit |
| 5 | Pure weekly-list builder + owning `ShoppingPlanService` + endpoint | Commit 5 | `php artisan test --filter=ShoppingPlanServiceTest`, `--filter=WeeklyPlanEndpointTest` | `GET /api/shopping/weekly-plan` against `wajaycha-1` with declared habits and a stocked pantry | Revert the commit; no persisted state depends on it — the plan is never stored |

---

## Slice 1 — `products` catalogue + `Unit` enum + subdomain and doc registration

**Goal:** a global, upsertable product catalogue with a private per-user extension, and every
stale registration/doc pointer this design depends on corrected in the same slice.
**Spec:** *Global product catalogue* (both scenarios).

- [x] 1.1 RED — `tests/Feature/Shopping/ShoppingSchemaTest.php`: `products` columns/types
      (`user_id` nullable bigint, FK `cascadeOnDelete`; `slug` nullable `text`; `name` `text`
      not null; `unit` `text` not null; `is_active` boolean default `true`; `timestamptz`
      timestamps); `unq_products_slug`, `unq_products_user_id_name` exist
- [x] 1.2 GREEN — `database/migrations/…_create_products_table.php` (M1) with those columns,
      constraints and Spanish `$table->comment(...)` on the table and principal columns
- [x] 1.3 Create `app/Enums/Unit.php` (`Kg|G|L|Ml|Unidad|Atado|Paquete`, no `fromColumn()`
      fallback — D4) and `app/Models/Product.php` (`HasFactory`, `$fillable`, no service call)
      with `database/factories/ProductFactory.php`
- [x] 1.4 RED — `tests/Feature/Shopping/ProductCatalogueSeederTest.php`: running the seeder
      twice inserts no duplicate row and never touches a user-owned product (`slug` NULL,
      `user_id` set)
- [x] 1.5 GREEN — `config/shopping.php` (`grocery_category_name`, `catalogue`: ~40 Peruvian
      staples with slug/name/unit) and `database/seeders/ProductCatalogueSeeder.php` using
      `Product::upsert($rows, uniqueBy: ['slug'], update: ['name','unit','is_active'])` — do
      **not** copy `PaymentServicesSeeder::insert()`
- [x] 1.6 RED — `tests/Feature/Shopping/ProductCatalogueEndpointTest.php`: `GET
      shopping/products` is 401 unauthenticated; two users see the same seeded list; a user's
      own private addition is invisible to another user
- [x] 1.7 GREEN — `app/Repositories/Contracts/ShoppingRepositoryContract.php`
      (`listProductsFor(int $userId): array`), `app/Repositories/ShoppingRepository.php`
      (`WHERE user_id IS NULL OR user_id = :userId`), `app/Http/Controllers/Shopping/
      ProductCatalogueController@index`, `GET shopping/products` inside the `JwtMiddleware`
      group at `routes/api.php:38`, bind the contract in `AppServiceProvider`
- [x] 1.8 RED — same test file: `POST shopping/products` creates a private addition
      (`user_id` = caller, `slug` NULL) invisible to another user; a name colliding
      case-insensitively with a visible global product is rejected 422; an unsupported `unit`
      is rejected 422
- [x] 1.9 GREEN — `app/Http/Requests/Shopping/StoreProductRequest.php`
      (`Rule::enum(Unit::class)`, case-insensitive name collision check) +
      `ProductCatalogueController@store` + `ShoppingRepositoryContract::createProduct()`.
      **Note:** the orchestrator amended `spec.md`'s "Seeded global catalogue with private
      extension" requirement before apply to match design D3 — the wording gap is RESOLVED,
      no further owner action needed
- [x] 1.10 Add `openspec/config.yaml` `Shopping` (`kind: supporting`) subdomain entry
- [x] 1.11 Add `openspec/config.yaml` `Financial Coaching` (`kind: core`) subdomain entry —
      separate task: pre-existing drift already recorded in `docs/domain/context-map.md:103`,
      not otherwise touched by this change
- [x] 1.12 Fix three stale paths in `openspec/config.yaml`: `context_sources.repo_rules`
      (lines 34-36) and `rules.design` / `rules.apply.guidelines` (lines 106, 114) — point at
      `CLAUDE.md`, which replaced the deleted `.agents/rules/*.md`. **Deviation:** written as
      `CLAUDE.md`, not `wajaycha-back-laravel/CLAUDE.md`, to match this file's own established
      convention — every other path in `config.yaml` (`../CLAUDE.md`, `../docs/...`) resolves
      relative to the repo root (`wajaycha-back-laravel/`), so prefixing the repo's own name
      would have pointed one level too deep
- [x] 1.13 Correct `wajaycha-back-laravel/CLAUDE.md:45` — replace "No migration uses `fk_`"
      with a note that the prefix is used for named composite FKs (D9), citing
      `2026_08_11_100000_enforce_cross_user_ownership.php:111,117,128,134`
- [x] 1.14 (Spanish) `docs/domain/context-map.md`: add the `Shopping` node, dashed inbound-only
      edges from Categorization and Financial Analysis, and one `Relaciones` row
- [x] 1.15 (Spanish) `docs/domain/ubiquitous-language.md`: add four terms — Product, Pantry
      Item, Consumption Habit, Grocery Budget Link — each stating what it is *not*
- [x] 1.16 Regenerate `docs/domain/data-dictionary.md`. **DONE by the orchestrator after
      diagnosing the block.** The apply phase was right to refuse and revert, but its
      diagnosis was wrong: `wajaycha_audit` is not "behind" the real schema, it is a
      DIFFERENT schema lineage. Measured table counts in the docker instance
      (`wajaycha-postgres-1`, host port 55433): `wajay` 27, `wajaycha` 29, `wajaycha-1` 30,
      `wajaycha_audit` 30 — and **none of them contains `products`**.
      The real target is the NATIVE PostgreSQL on port 5432, database `wajaycha_bk`.
      `php artisan tinker` resolves `pgsql -> 127.0.0.1:5432/wajaycha_bk`, and
      `php artisan migrate:status` reports `2026_09_26_100000_create_products_table [4] Ran`
      there. The docker stack is effectively unused for this repository.
      Regenerated with the script's own documented escape hatch — a process-level
      `MCP_DATABASE_URI` wins over both env files — built from Laravel's own resolved config
      so no credential was printed. Result: **+26 -2 lines, adding only `products`**. No
      regression.
      OPEN FOR THE OWNER, not blocking: `.env.mcp.local` still redirects the generator (and
      the `wajaycha-db` MCP server) to `wajaycha_audit` on the docker instance. Either
      repoint it at `127.0.0.1:5432/wajaycha_bk` or expect to pass `MCP_DATABASE_URI`
      explicitly on every future run. Slices 2-4 each end in this same task.
- [x] 1.17 Run `./vendor/bin/phpstan analyse` and `./vendor/bin/pint --test`; no baseline growth.
      Both required `--memory-limit`/`-d memory_limit` overrides beyond this machine's 128M PHP
      CLI default to complete — a pre-existing environment constraint, unrelated to this slice

---

## Slice 2 — `pantry_items` + `AcquisitionSource` + CRUD

**Goal:** per-user pantry recording with acquisition source and optional expiry.
**Spec:** *Pantry item recording*, *Per-user isolation* (pantry half).
**Depends on:** Slice 1 (`products`, unit validation pattern).

- [x] 2.1 Create `app/Enums/AcquisitionSource.php` (`Purchased|Gift|Harvested`)
- [x] 2.2 RED — `ShoppingSchemaTest.php` (extend): `pantry_items` columns/types
      (`quantity numeric(12,3)`, `unit text`, `acquisition_source text`, `acquired_on date`
      default today, `expires_on` nullable `date`, `note` nullable), `product_id` FK
      `restrictOnDelete`, `idx_pantry_items_user_product`, `idx_pantry_items_user_expires`
- [x] 2.3 GREEN — `database/migrations/2026_09_27_100000_create_pantry_items_table.php` (M2)
      + `app/Models/PantryItem.php` + `database/factories/PantryItemFactory.php`
- [x] 2.4 RED — `tests/Feature/Shopping/PantryItemEndpointTest.php`: `POST
      shopping/pantry-items` is 401 unauthenticated; persists with its `acquisition_source`; a
      unit mismatch against the product's canonical unit is rejected 422 and not persisted
- [x] 2.5 GREEN — `app/Http/Requests/Shopping/StorePantryItemRequest.php` (the composed
      `Rule::exists('products','id')` visibility + unit trick from `StoreCategoryRequest.php:
      23-31`), `app/Actions/Shopping/StorePantryItemAction.php`,
      `ShoppingRepositoryContract::createPantryItem()`, `PantryItemController@store`, route.
      `index`/`GET shopping/pantry-items` landed in the same GREEN batch (same pairing as
      slice 1 tasks 1.7/1.9), which is why 2.6's list-only-caller's-items case was already
      green the moment it was written
- [x] 2.6 RED — same test file: `GET shopping/pantry-items` lists only the caller's items;
      `PUT`/`DELETE shopping/pantry-items/{pantryItem}` succeed for the owner
- [x] 2.7 GREEN — `index`/`update`/`destroy` on `PantryItemController`,
      `UpdatePantryItemAction`, matching `ShoppingRepositoryContract` methods, routes.
      **Deliberately unscoped** at this step (`PantryItem::query()->find($id)`, no `user_id`
      check) so that 2.8's cross-user test would fail for the right reason instead of the
      real fix landing before its own RED test existed
- [x] 2.8 RED — extend `tests/Feature/Security/CrossUserAccessTest.php`: another user's
      `pantry_items` id returns 404 on `PUT`/`DELETE`, not 403. Confirmed genuinely RED
      (200, not 404) against the unscoped 2.7 implementation before proceeding
- [x] 2.9 GREEN — resolve `{pantryItem}` through
      `ShoppingRepositoryContract::findPantryItem(int $id, int $userId)` (D8, no route model
      binding); a miss returns 404
- [x] 2.10 Regenerate `docs/domain/data-dictionary.md`. Additive diff, +55/-2, adding both
      `pantry_items` (this slice) and `products` (slice 1's regeneration — done in that
      session per task 1.16 but never committed to the separate `docs/` git repository, so it
      reappeared as uncommitted here) plus two unrelated pre-existing columns on `users`
      (`google_id`, `password` nullability) that predate this change and were likewise never
      captured. No table dropped; nothing shipped by this change is missing. Left uncommitted
      — `docs/` is a separate repository outside this attempt's scope
- [x] 2.11 Run `./vendor/bin/phpstan analyse` and `./vendor/bin/pint --test`; no baseline
      growth. Same `--memory-limit=1G` / `-d memory_limit=1G` overrides as slice 1

---

## Slice 3 — `consumption_habits` + CRUD

**Goal:** per-user declared weekly need per product.
**Spec:** *Declared consumption habit*, *Per-user isolation* (habits half).
**Depends on:** Slice 1. Reuses slice 2's `Rule::exists` and D8 patterns verbatim.

- [x] 3.1 RED — `ShoppingSchemaTest.php` (extend): `consumption_habits` columns
      (`weekly_quantity numeric(12,3)`, `unit text`, `is_active boolean` default `true`),
      `product_id` FK `restrictOnDelete`, `unq_consumption_habits_user_id_product_id`,
      `idx_consumption_habits_user_active`
- [x] 3.2 GREEN — `database/migrations/…_create_consumption_habits_table.php` (M3) +
      `app/Models/ConsumptionHabit.php` + `database/factories/ConsumptionHabitFactory.php`
- [x] 3.3 RED — `tests/Feature/Shopping/ConsumptionHabitEndpointTest.php`: `POST
      shopping/consumption-habits` persists a weekly quantity/unit scoped to the caller; a
      unit mismatch is rejected 422; declaring the same product twice updates, not duplicates
- [x] 3.4 GREEN — `app/Http/Requests/Shopping/StoreConsumptionHabitRequest.php` (same composed
      `Rule::exists` trick), `app/Actions/Shopping/StoreConsumptionHabitAction.php`,
      `ShoppingRepositoryContract::createConsumptionHabit()` / `activeHabitsFor()`,
      `ConsumptionHabitController@store` / `@index`, routes
- [x] 3.5 RED — same test file: `PUT`/`DELETE shopping/consumption-habits/{consumptionHabit}`
      succeed for the owner; extend `CrossUserAccessTest`: another user's id returns 404.
      **Deviation from the literal task order:** `findConsumptionHabit` was implemented scoped
      (D8) in the same GREEN batch as 3.4/3.6 rather than staged unscoped-then-scoped the way
      slice 2 did. To honour "make sure that test is genuinely RED" anyway, the scoping was
      temporarily reverted to `ConsumptionHabit::query()->find($id)` after the full GREEN
      landed, the cross-user PUT/DELETE tests were re-run and confirmed to fail with 200 (not
      404) against that unscoped version, then the D8-scoped lookup was restored and the suite
      re-confirmed green — same genuine-RED evidence slice 2 produced, different ordering
- [x] 3.6 GREEN — `UpdateConsumptionHabitAction`, `ConsumptionHabitController@update` /
      `@destroy`, `findConsumptionHabit(int $id, int $userId)` (D8), routes
- [x] 3.7 Regenerate `docs/domain/data-dictionary.md`. Additive diff, +81/-2, adding
      `consumption_habits` (this slice) plus `pantry_items` and `products` (slices 1-2's
      regenerations, never committed to the separate `docs/` git repository, so they reappear
      here too — same pattern slice 2 already recorded). No table dropped. Left uncommitted —
      `docs/` is a separate repository outside this attempt's scope
- [x] 3.8 Run `./vendor/bin/phpstan analyse` and `./vendor/bin/pint --test`; no baseline
      growth. Same `--memory-limit=1G` override as slices 1-2. `phpstan`: 210/210 files, 0
      errors. `pint --test` (whole project): only pre-existing unrelated failures across
      migrations/config/seeders/tests that predate this change; all 14 files this slice touched
      pass individually

---

## Slice 4 — `grocery_budget_links` + lazy-resolve-then-pin + composed ceiling read

**Goal:** the pinned category lifecycle and the composed spend/ceiling read, with no new
raw query against `categories` or `transactions`.
**Spec:** *Ceiling is absent, exceeded, or present — never zero*, *Lazy-resolve-then-pin
lifecycle*, *Per-user isolation* (link half).
**Depends on:** Slice 1. Composes `CategoryRepositoryContract` and the existing
`TransactionRepositoryContract::expenseByCategoryBetween()` — no new method on either.

- [x] 4.1 RED — `ShoppingSchemaTest.php` (extend): `grocery_budget_links` columns
      (`category_id bigint`, `resolved_by text`, `linked_at timestamptz`),
      `unq_grocery_budget_links_user_id`, `idx_grocery_budget_links_category_user`, and the
      raw composite FK `fk_grocery_budget_links_category_id (category_id, user_id) REFERENCES
      categories (id, user_id) ON DELETE CASCADE`. Confirmed genuinely RED (3 new assertions
      failed — table did not exist) before the migration landed
- [x] 4.2 GREEN — `database/migrations/2026_09_29_100000_create_grocery_budget_links_table.php`
      (M4) with the raw-SQL composite FK (the Schema builder cannot express it), `ON DELETE
      CASCADE` written explicitly per D9/M4 (the precedent migration's own composite FKs have
      no `ON DELETE` clause and default to `NO ACTION`) + `app/Models/GroceryBudgetLink.php`
      + `database/factories/GroceryBudgetLinkFactory.php`
- [x] 4.3 — `tests/Feature/Shopping/GroceryBudgetLinkCascadeTest.php`: deleting the pinned
      `Category` succeeds (physical delete, no `SoftDeletes`) and removes the pin row; the
      capability returns to no-link. **Deviation:** written and run after 4.2's migration
      already existed (task 4.2 precedes 4.3 in this same list), so it was not genuinely RED —
      it passed on arrival, exactly as 4.4 anticipates ("no application code needed"). Its
      purpose was to prove the cascade against real PostgreSQL end-to-end via an actual
      `Category::delete()`, distinct from `ShoppingSchemaTest`'s structural `pg_constraint`
      check (task 4.1)
- [x] 4.4 GREEN — confirmed the migration's `ON DELETE CASCADE` against real PostgreSQL
      `wajaycha_bk`; no application code needed — the constraint is the arbiter
- [x] 4.5 Created `app/DTOs/Shopping/GroceryCategoryMatch.php` and `GroceryCeilingInput.php`
      (plain DTOs, no `App\Models` import). Structural, no branching — triangulation
      deliberately skipped per strict-tdd.md
- [x] 4.6 RED — `tests/Feature/Shopping/GroceryCategoryResolutionTest.php`: no link + a
      matching category name resolves once and pins (`resolved_by: 'auto'`); a renamed
      category yields `name_not_found` and writes nothing; two concurrent resolution attempts
      are absorbed by `unq_grocery_budget_links_user_id`, both returning the same pin.
      **Discovery mid-task:** `User::factory()->create()` already seeds a leaf `Category`
      named exactly `config('shopping.grocery_category_name')` via `UserObserver` →
      `SeedDefaultWorkspaceAction` (`config/onboarding.php:69`, `'🍽️ Alimentación' >
      '🛒 Supermercado'`). The first test draft manually created a second category with the
      same name and got an ambiguous match (wrong id) instead of a clean RED — rewritten to
      use the onboarding-seeded category directly, which is the realistic case this feature
      is built around. Confirmed genuinely RED (BindingResolutionException — contract/action
      did not exist) before implementation
- [x] 4.7 GREEN — `app/Repositories/Contracts/GroceryBudgetRepositoryContract.php`
      (`findLink`, `findCategoryByExactName` — exact string equality, `$search` unused,
      filtering `CategoryRepositoryContract::getAllForUser()` in PHP — `pin`),
      `app/Repositories/GroceryBudgetRepository.php`, `app/Actions/Shopping/
      ResolveGroceryCategoryAction.php`, bound in `AppServiceProvider`. **Deviation:** `pin()`
      is implemented as `GroceryBudgetLink::upsert([...], uniqueBy: ['user_id'], update:
      [...])` (PostgreSQL `INSERT ... ON CONFLICT (user_id) DO UPDATE`, the `Product::upsert()`
      precedent from slice 1's seeder) rather than a manual insert-then-catch-QueryException.
      A single atomic upsert satisfies both call sites with one statement: the auto-resolve
      race (both callers resolved the same name, so the last writer's identical values are
      harmless) and the manual override's genuine "replace whichever category was pinned
      before" — the unique index remains the sole concurrency arbiter either way
- [x] 4.8 RED — `tests/Feature/Shopping/GroceryCeilingReadTest.php`: no link → `unlinked`;
      pinned with `monthly_budget = 0` → `unbudgeted`; pinned and budgeted → `set` with spend
      from `v_unified_transactions`; a reconciled Yape/bank pair counts once. `ceilingFor()`
      was stubbed to throw `LogicException` after 4.7 so this test would genuinely RED rather
      than pass on an accidental early implementation — confirmed all 4 cases failed with that
      exception before 4.9's real implementation landed
- [x] 4.9 GREEN — `GroceryBudgetRepositoryContract::ceilingFor(int $userId, CarbonImmutable
      $asOf): ?GroceryCeilingInput` composing `CategoryRepositoryContract::findById()` and the
      existing `TransactionRepositoryContract::expenseByCategoryBetween()`. **Deviation from
      the literal signature:** takes `CarbonImmutable $asOf`, not `PlanningWeek $week`.
      `PlanningWeek` is slice 5's own DTO (tasks.md 5.1-5.2) and slice 4 is scoped to depend on
      slice 1 only ("STOP when slice 4 is complete. Do NOT start slice 5") — creating it here
      would be slice-5 work landing early. `$asOf` is the only field of `PlanningWeek` this
      method actually needs: design.md D5's window table keys off the reference date via
      `BudgetPeriod`, never the Monday/Sunday week bounds. Forward-compatible — slice 5's
      `BuildWeeklyPlanAction` can call this with `$week->asOf` once `PlanningWeek` exists
- [x] 4.10 RED — `tests/Feature/Shopping/GroceryBudgetLinkEndpointTest.php`: `GET
      shopping/grocery-budget-link` is 401 unauthenticated; reports `unlinked` on a first call
      where the onboarding-seeded category was renamed away (no match), then auto-pins
      (`ceiling_state: 'set'`) once the name is restored on a second call; `PUT
      shopping/grocery-budget-link` upserts a manual pin (`resolved_by: 'manual'`) and a later
      `GET` never re-resolves afterward (step 1 — `findLink` — always wins). Confirmed
      genuinely RED (404 — routes did not exist) before the controller/routes landed
- [x] 4.11 GREEN — `app/Http/Requests/Shopping/UpdateGroceryBudgetLinkRequest.php`
      (`Rule::exists('categories','id')->where('user_id', …)`),
      `GroceryBudgetLinkController@show` / `@update`, `GET`/`PUT
      shopping/grocery-budget-link` routes. `show()` is the GET that writes (D6) — calls
      `ResolveGroceryCategoryAction` then translates the pin plus the composed ceiling read
      into `unlinked | unbudgeted | set`, never a bare zero (D7: no API Resources layer)
- [x] 4.12 RED — extended `tests/Feature/Security/CrossUserAccessTest.php`: a stranger's
      `category_id` cannot be pinned through `PUT shopping/grocery-budget-link` (422, no row
      written)
- [x] 4.13 GREEN — confirmed by 4.11's `FormRequest` scope, exactly as this task predicted:
      the test passed on arrival with no further implementation
- [x] 4.14 Ran `tests/Feature/Database/CrossUserOwnershipTest.php` and
      `tests/Unit/Architecture/BoundariesTest.php` unmodified — both pass unchanged
      (regression gate)
- [x] 4.15 Regenerated `docs/domain/data-dictionary.md`. Additive, +106/-2 (same two
      pre-existing/unrelated lines slices 2-3 already recorded — the generation timestamp and
      `users.password` nullability). Adds `grocery_budget_links`; no table dropped
- [x] 4.16 Ran `./vendor/bin/phpstan analyse --memory-limit=1G` (Larastan level 6): one error
      found and fixed (`Collection::first()` closure typed `Category` instead of the
      collection's declared `Model` element type — changed to `fn (Model $candidate): bool =>
      $candidate instanceof Category && ...`); re-run: 218/218 files, 0 errors, no baseline
      growth. `./vendor/bin/pint --test` on all 18 slice-4 files (new + extended): clean

---

## Slice 5 — Pure weekly-list builder + owning `ShoppingPlanService` + endpoint

**Goal:** the read-only weekly plan: habit-minus-pantry lines plus the composed ceiling
reading, computed and never persisted.
**Spec:** *Weekly list derivation*, *Expired items excluded*, *Gift subtracts from list, not
budget*, *Ceiling is absent, exceeded, or present — never zero* (structural guarantee).
**Depends on:** Slices 1-4.

- [x] 5.1 RED — `tests/Unit/Shopping/PlanningWeekTest.php`: Monday-to-Sunday boundaries in
      `America/Lima`, including a Sunday `asOf`
- [x] 5.2 GREEN — `app/DTOs/Shopping/PlanningWeek.php` (`forDate()` named constructor, the
      `ParetoWindow::forFilter()` shape)
- [x] 5.3 Create the remaining plain DTOs, no `App\Models` import: `ConsumptionHabitLine`,
      `PantryStock`, `ShoppingListLine`, `CoveredLine`, `WeeklyShoppingPlan`,
      `GroceryCeilingReading`
- [x] 5.4 RED — `tests/Unit/Shopping/ShoppingPlanServiceTest.php`: habit-minus-pantry netting;
      the expiry cut at `asOf` (expired sums into `expiredQuantity`, subtracts nothing, row
      untouched); unit mismatch (including `null`) sums into `unmatchedUnitQuantity`, raises
      `hasUnitMismatch`, never converted; the `lines`/`covered` split; the three ceiling
      states; an exceeded ceiling leaves `lines` byte-identical to the non-exceeded case;
      `MONTHLY` vs `YEARLY` window selection
- [x] 5.5 GREEN — `app/Services/Shopping/ShoppingPlanService.php` with `plan(array $habits,
      array $pantry, ?GroceryCeilingInput $ceiling, PlanningWeek $week): WeeklyShoppingPlan`
      — no connection, no model, no clock, no `DB::` call
- [x] 5.6 Add `App\Services\Shopping` to the `toUseStrictTypes()` list at
      `tests/Unit/Architecture/BoundariesTest.php:197`; confirm the seven existing
      architecture rules keep passing unchanged
- [x] 5.7 RED — `tests/Feature/Shopping/WeeklyPlanEndpointTest.php`: `GET
      shopping/weekly-plan` is 401 unauthenticated; a user with declared habits and a stocked
      pantry receives only the difference; a gift fully covering a habit excludes the product
      and touches no `Transaction`; an exceeded ceiling states the overspend and still lists
      every needed product
- [x] 5.8 GREEN — `ShoppingRepositoryContract::pantryStockFor(int $userId): array` (returns
      `PantryStock[]`) and `activeHabitsFor(int $userId): array` (returns
      `ConsumptionHabitLine[]`), `app/Actions/Shopping/BuildWeeklyPlanAction.php` (builds
      `PlanningWeek::forDate(Carbon::now())`, calls both repositories and `ShoppingPlanService
      ::plan()`, returns `$plan->toArray()`), `WeeklyPlanController@show`, `GET
      shopping/weekly-plan` route. **Deviation:** `activeHabitsFor()` itself was left
      UNCHANGED — it still returns `ConsumptionHabit[]` and still serves
      `ConsumptionHabitController@index`'s CRUD read, which needs each row's own `id` to
      `PUT`/`DELETE` it. A new method, `activeHabitLinesFor(int $userId): array` (returns
      `ConsumptionHabitLine[]`), was added instead — one method name cannot honestly return
      two incompatible shapes for two different callers. See `ShoppingRepositoryContract.php`'s
      own docblock on the new method
- [x] 5.9 Regenerate `docs/domain/data-dictionary.md` (no new migration this slice — confirmed
      a no-op: only the generation timestamp changed once read against the correct
      `127.0.0.1:5432/wajaycha_bk` database; content otherwise identical to slice 4's state)
- [x] 5.10 Run the full suite (`php artisan test`), `./vendor/bin/phpstan analyse`,
      `./vendor/bin/pint --test`; confirm `CrossUserOwnershipTest` and `CrossUserAccessTest`
      still pass unchanged

---

## Definition of done

- [x] Every one of the scenarios in `specs/shopping/spec.md` has a covering test. **Note:**
      counted 18 scenario rows across the spec's 9 requirement tables (not 19 as this file's
      header states); every one of the 18 traces to a passing test across slices 1-5 — a
      formal per-scenario trace belongs to `sdd-verify`, this is apply's own good-faith count
- [x] `php artisan test` green against real PostgreSQL — **973 passed, 2494 assertions, 0
      failed** (against `wajaycha_bk`, the real target per task 1.16's finding; `wajaycha-1`
      named here does not exist on this machine)
- [x] `./vendor/bin/phpstan analyse` (Larastan level 6) clean, no baseline growth — 228/228
      files, 0 errors
- [x] `./vendor/bin/pint --test` clean on changed files — all 18 slice-5 files (new + shared)
      individually clean; whole-project run shows only pre-existing unrelated failures that
      predate this change (same pattern slice 3 already recorded)
- [x] `tests/Unit/Architecture/BoundariesTest.php`, `tests/Feature/Database/
      CrossUserOwnershipTest.php` pass unchanged — both re-run explicitly, same 8/8 and
      unchanged pass counts
- [x] `docs/domain/data-dictionary.md` regenerated; Shopping appears in both
      `openspec/config.yaml` and `docs/domain/context-map.md` — done in slice 1, confirmed
      still present
- [x] No code path ever writes a `Transaction` (QA-4, structural by construction) —
      `ShoppingPlanService` never imports `App\Models` or reads `acquisition_source`;
      `WeeklyPlanEndpointTest`'s gift scenario asserts `Transaction::query()->count()` is `0`

## Deferred, recorded so they are not forgotten

| Item | Where it goes |
|---|---|
| `wajaycha-front-vue` implementation | Later change, once the API has been exercised — see `state.yaml:frontend_scope`. `design.md` keeps the frontend design for reference |
| `spec.md`'s "visible to all" wording for user-submitted products vs. design D3's private-extension rule | Owner decision before `sdd-verify` traces that scenario (flagged at task 1.9) |
| Seasonality windows per product | Phase 2 — curated MIDAGRI/SIEA data |
| Live price data | Phase 3 |
| Unit conversion table | Later change, behind the same `Unit` enum |
| Composite FK guard on `pantry_items.product_id` | Follow-up once `products` gains a price (phase 3); accepted weakness recorded in D3 |
| Non-food products | Out of scope for a food-only phase 1 |
