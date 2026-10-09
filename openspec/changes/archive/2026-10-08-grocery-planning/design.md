# Design — Weekly grocery planning

- **Change:** `grocery-planning`
- **Owning subdomain:** Shopping — new logical subdomain, `kind: supporting`
- **Date:** 2026-09-26
- **Proposal:** [`proposal.md`](./proposal.md) · **Exploration:** [`exploration.md`](./exploration.md)

All owner-bound decisions are taken as given and are not reopened here. This document decides
*where the code goes and what each piece is allowed to know*.

## Owning service

| Subdomain | Owning service | State |
|---|---|---|
| Shopping | `App\Services\Shopping\ShoppingPlanService` | **new** |
| Categorization | `CategorizationService` | unchanged — consumed through `CategoryRepositoryContract` only |
| Financial Analysis | `FinancialReportService` | unchanged — consumed through `TransactionRepositoryContract` only |

Layout stays logical. Nothing moves to `app/Modules/` (ADR-0005, `BoundariesTest.php:231-238`).

`ShoppingPlanService` **is** the pure decider. It is the mapping of `ParetoReportBuilder`, one
name across:

| Pareto (the worked example) | Shopping |
|---|---|
| `ParetoReportBuilder` — values in, decisions out | `ShoppingPlanService` |
| `ParetoRepositoryContract` | `ShoppingRepositoryContract` + `GroceryBudgetRepositoryContract` |
| `BuildParetoReportAction` — wiring | `BuildWeeklyPlanAction` |

**What it decides**, and nothing else:

1. Which declared habits become a line to buy, and in what quantity.
2. Whether a pantry quantity counts as available, given `expires_on` and the unit.
3. Whether the ceiling is `unlinked`, `unbudgeted` or `set`, and whether it is exceeded.
4. Which window the ceiling is read over, given `BudgetPeriod`.

**What it must not do:** open a connection, import `App\Models`, import
`Illuminate\Http\Request` (`BoundariesTest.php:69-71`), call `now()`, or run
`DB::select|table|raw|statement|insert|update|delete` (`:116-138`). Its only signature is

```php
public function plan(
    array $habits,          // ConsumptionHabitLine[]
    array $pantry,          // PantryStock[]
    ?GroceryCeilingInput $ceiling,
    PlanningWeek $week,     // carries asOf; the Action supplies the clock
): WeeklyShoppingPlan
```

so `tests/Unit/Shopping/ShoppingPlanServiceTest.php` can hand it fixtures with no database —
the same property that makes `ParetoReportBuilderTest` and `PaceEvaluatorTest` live under
`tests/Unit/`.

**The line between it and the Actions.** Actions read, write and wire; they decide nothing.
`BuildWeeklyPlanAction` calls the two repositories, builds `PlanningWeek` from `Carbon::now()`
— exactly as `BuildParetoReportAction` passes `today: Carbon::now()` into
`ParetoWindow::forFilter()` — hands values to the service, and returns
`$plan->toArray()`. The CRUD actions (`StorePantryItemAction`, `UpdatePantryItemAction`,
`StoreConsumptionHabitAction`, `StoreProductAction`, `ResolveGroceryCategoryAction`) persist
through the repository and hold no rule.

## Architecture decisions

### D1 — One pure service, not a service plus a builder

**Choice.** `ShoppingPlanService` holds every rule and is itself pure. There is no separate
`ShoppingPlanBuilder`.

**Rejected.** The proposal's wording ("the owning service; the pure builder beside it") reads as
two classes. Rejected because the service would then contain no decision, only a delegation, and
ADR-0005's "one owning Service per subdomain" would name a class that decides nothing. Pareto has
no such pair either: `ParetoReportBuilder` is the decider and `BuildParetoReportAction` is the
wiring.

**Consequence.** `ShoppingPlanService` is unit-testable with no database, which is the property
the proposal actually asked for. This is a deliberate refinement of the proposal's wording, not a
scope change.

### D2 — Two repository contracts, and the composition lives in one of them

**Choice.** Two contracts in the flat `app/Repositories/Contracts/` (the existing layout — every
contract in the repository sits at that root):

| Contract | Owns | Composes |
|---|---|---|
| `ShoppingRepositoryContract` | `products`, `pantry_items`, `consumption_habits` | nothing |
| `GroceryBudgetRepositoryContract` | `grocery_budget_links` | `CategoryRepositoryContract`, `TransactionRepositoryContract` |

`GroceryBudgetRepository` constructor-injects the two existing contracts and translates their
output — including the Eloquent `Category` that `CategoryRepositoryContract::findById()` returns
— into `GroceryCeilingInput`, a plain DTO, before anything else sees it.

**Rejected — one contract.** It would put the subdomain's own tables and the two foreign
subdomains behind one name, so a change to the ceiling read would touch the interface the pantry
CRUD depends on.

**Rejected — compose in the Action.** The Action would then decide which date window to read and
how to pair ceiling with spend. That is read-model work, and ADR-0005 says Actions orchestrate.

**Why a repository may depend on repositories.** `BoundariesTest` forbids raw queries in
`App\Services` and models in `App\DTOs`; it says nothing against composition inside
`App\Repositories`, and composition is what keeps the claim "no new raw query against
`categories` or `transactions`" literally true — `GroceryBudgetRepository` issues none. It
delegates.

**No new method on a foreign contract.** `expenseByCategoryBetween()` already exists (F2).
The exact-name lookup needed for resolution is `GroceryBudgetRepositoryContract::findCategoryByExactName(int $userId, string $name): ?GroceryCategoryMatch`,
implemented by filtering `CategoryRepositoryContract::getAllForUser($userId)` in PHP over a
user's few dozen categories. Its `$search` argument is deliberately unused: it is
`ILIKE '%…%'`, which can match two categories, and a near-match is the user's own rename and
must not be guessed.

### D3 — `products` is global with a private extension, not purely global

**Choice.** `products.user_id` is **nullable**. `NULL` means the shared seeded catalogue;
non-null means one user's own addition. Every read filters
`WHERE user_id IS NULL OR user_id = :userId`.

**Why the proposal's "Global, no `user_id`" cannot stand.** Open question q3 is answered *yes* —
a user may add a product the catalogue lacks. With no `user_id` column, that addition is visible
in every other user's picker. `FinancialEntity` and `PaymentService` are genuinely global because
nothing writes to them at runtime; `products` has a `POST` endpoint, so the precedent does not
transfer intact.

**Rejected — a second `user_products` table.** Two tables for one concept, and every read becomes
a union.

**Rejected — defer user additions to phase 2.** It makes the catalogue a ceiling on the product,
which q3 already refused.

**Accepted weakness, stated plainly.** `pantry_items.product_id` stays a **plain single-column
FK**, so the schema cannot prove a user did not reference another user's private product — a
composite `(product_id, user_id) → products (id, user_id)` FK is impossible here, because global
rows carry `user_id IS NULL` and under `MATCH SIMPLE` the non-null referencing pair could never
satisfy it. Visibility is therefore enforced in the FormRequest (D7). The exposure is a product
*name* and nothing else: no money, no quantity, no other user's pantry. It becomes worth
schema-level guarding when `products` gains a price in phase 3, and that is recorded as a
follow-up rather than solved now.

### D4 — Unit is stored on both child tables and never converted

**Choice.** `pantry_items.unit` and `consumption_habits.unit` store the `Unit` value, validated
at write time against `products.unit`.

**Rejected — read the unit from `products` only.** It removes the duplication but also removes
the evidence: if a product's canonical unit is later corrected from `unidad` to `kg`, every
historical row silently changes meaning. A stored unit makes that correction surface as a
mismatch instead (QA-3).

**The mismatch rule, in two places for two reasons.** The FormRequest rejects a mismatch with a
422 so the state is unreachable through the API. `ShoppingPlanService` additionally refuses to
*net* a mismatched pair: the quantity is reported in `unmatchedUnitQuantity` and
`hasUnitMismatch` is raised, but it subtracts nothing. Treating 500 `g` as 500 `kg` is the
failure this rule exists to prevent, and a row written outside the application must not cause it.

**`Unit` gets no `fromColumn()` fallback.** `BudgetPeriod::fromColumn()` falls back to `MONTHLY`
because a bad row must not explode the nightly sweep and `MONTHLY` is what every row meant before
the column existed. `Unit` has no such safe default — coercing `atado` to `kg` mis-scales a
quantity. `Unit::tryFrom()` returning `null` becomes the mismatch flag. Copying
`fromColumn()` here would be the bug.

### D5 — The ceiling has three states, and no data path into the lines

**Choice.** `ceiling_state` is `unlinked | unbudgeted | set`, and `amount` is `null` in the first
two.

| State | When | `amount` |
|---|---|---|
| `unlinked` | no link row; or the link's category no longer resolves for this user | `null` |
| `unbudgeted` | link resolves but `categories.monthly_budget <= 0` | `null` |
| `set` | link resolves and the budget is positive | the amount |

**Rejected — two states.** A pinned category with a zero budget is not the same fact as no link,
and collapsing them reproduces the silent zero from the other direction: the user is told to link
a category they already linked.

**`spent` may legitimately be `0.0`.** `expenseByCategoryBetween()` returning no row for the
pinned category means no transactions, which is genuinely zero. Only the *ceiling* is absent-or-
present; the spend is a measurement.

**`BudgetPeriod` is honoured, not flattened.** Groceries are a *ritmo* (F2), so the expected path
is `MONTHLY`. The window is half-open in both cases:

| `budget_period` | Window | Ceiling |
|---|---|---|
| `MONTHLY` | `[first day of asOf's month, first day of next month)` | `monthly_budget` |
| `YEARLY` | `[Jan 1 of asOf's year, Jan 1 of next year)` | the annual amount, **not a twelfth** |

The twelfth is deliberately absent. `BudgetedCategoryRow::monthlyWeight()` documents that a
twelfth is only ever valid for *distribution* and never as a denominator for a period's spending;
dividing an envelope by twelve here would report "you passed your grocery budget" on a budget the
user did not pass. The response echoes `budget_period` and the window so the SPA cannot present a
yearly envelope as a monthly figure.

**Structural guarantee for q6.** `plan()` computes `lines` from `(habits, pantry, week)` and the
ceiling reading from `(ceiling, week)`. The two share no term. An exceeded ceiling therefore
*cannot* alter, reorder, hide or prioritise a line — the guarantee is in the data flow, not in
discipline. The RED test is: the `lines` array is identical for the same habits and pantry across
all three ceiling states and across an exceeded one.

### D6 — The pin is written on a read, and the unique index is the arbiter

**Choice.** `GET /api/shopping/weekly-plan` (and `GET /api/shopping/grocery-budget-link`) may
create the pin through `ResolveGroceryCategoryAction`.

1. `findLink($userId)` — present → done. **No name is read, ever.**
2. Absent → `findCategoryByExactName($userId, config('shopping.grocery_category_name'))`.
   Exact string equality, not `ILIKE`, not trigram.
3. No match → return the absent state with `resolution: "name_not_found"`. **No link is
   written**, no exception is thrown. A rename is legitimate and must not be an error.
4. Match → `pin($userId, $categoryId, resolvedBy: 'auto')`.

**Concurrency.** Two simultaneous requests both resolve, both insert.
`unq_grocery_budget_links_user_id` rejects the second; the repository swallows the unique
violation and **re-reads**, returning whichever pin won. Both had resolved the same name, so the
race has no observable outcome. This is the posture `processed_channel_updates` already
established — the guarantee lives in the index, not in application logic, so a race between two
workers cannot double-insert.

**A GET that writes, defended.** The write is idempotent and converging, it is derived entirely
from rows the user already owns, and it memoises a resolution. **Rejected alternative:** pin only
on an explicit `POST`. That leaves every existing user unlinked until they find a settings screen
— the same backfill problem that got the seed-time alternative rejected in the proposal.
**Rejected alternative:** re-match by name on every read. That is precisely the anti-pattern F3
exists to kill.

**The pin's uniqueness is `UNIQUE (user_id)`**, not `(user_id, category_id)`. One pin per user is
q7's answer; `(user_id, category_id)` would permit two, which is the phase-2 question.

**Manual override.** `PUT /api/shopping/grocery-budget-link` upserts on `user_id` with
`resolved_by: 'manual'`. Once manual, step 2 never runs again for that user.

### D7 — No new API Resources layer

**Choice.** Controllers return `response()->json(['data' => …])` over a DTO's `toArray()`.

**Verified:** there is no `app/Http/Resources/` directory and nothing under `app/` imports
`JsonResource` or `ResourceCollection`. The precedents are `ParetoBandReport::toArray()` and
`ChannelIdentityController`, which hand-builds its payload.

**Rejected.** Introducing `Illuminate\Http\Resources\Json\JsonResource` would add a serialisation
layer this repository does not have, in a change that has no reason to introduce one.

### D8 — Route model binding is not used

**Choice.** `{pantryItem}` and `{consumptionHabit}` arrive as integers and are resolved through
`ShoppingRepositoryContract::findPantryItem(int $id, int $userId)`.

Implicit binding resolves by id alone and hands back another user's row. The repository rule this
codebase already writes down —
"`$userId` is an AUTHORIZATION BOUNDARY, not a convenience filter … a caller that cannot supply
the owner has no business calling this method" (`TransactionRepositoryContract.php:26-31`) — is
the one to copy. A miss returns **404, not 403**: 403 confirms the row exists.

### D9 — The `fk_` prefix is adopted, and `CLAUDE.md` is wrong about it

`CLAUDE.md` states "**No migration uses `fk_`**". That is stale. Verified: four constraints in
`database/migrations/2026_08_11_100000_enforce_cross_user_ownership.php` are named
`fk_transactions_detail_id`, `fk_transactions_category_id`,
`fk_categorization_rules_detail_id`, `fk_categorization_rules_category_id` (lines 111, 117, 128,
134, dropped again at 149-153).

**Choice.** New work adopts `fk_` for the named composite FK, which is the exact case the
precedent covers. Laravel-generated names stay on the single-column `foreignId()->constrained()`
FKs, because naming those explicitly would mean dropping and re-adding constraints the Schema
builder writes for free. Correcting the `CLAUDE.md` sentence is a slice-1 docs task.

## The four migrations

Every table and principal column carries `$table->comment(…)` in **Spanish** — it feeds the
generated `docs/domain/data-dictionary.md`, which is regenerated with
`./scripts/generate-data-dictionary.sh` and never hand-edited. Quantities are `numeric`, never
`float`. Timestamps are `timestamptz`. `expires_on` and `acquired_on` are `date`, not
`timestamptz`, for the reason `coaching_observations.period_month` records: they are calendar
labels, and storing an instant reintroduces the ambiguity the column exists to remove.

**Zero new PostgreSQL functions, views or triggers.** ADR-0009 is satisfied by construction: the
rules live in `ShoppingPlanService`.

### M1 — `products` (global, with private extension)

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `bigserial` | no | `$table->id()` |
| `user_id` | `bigint` | **yes** | FK → `users`, `cascadeOnDelete`. `NULL` = shared catalogue; non-null = this user's own addition (D3) |
| `slug` | `text` | **yes** | Stable identifier for seeded rows only. `NULL` on user additions — that is what makes the seeder's upsert key work |
| `name` | `text` | no | Canonical Spanish name, e.g. `Papa blanca` |
| `unit` | `text` | no | `Unit` value. **No DB CHECK** — validated in the FormRequest, the `BudgetPeriod` / `ImportStatus` precedent |
| `is_active` | `boolean` default `true` | no | Retires a catalogue entry without deleting a row that pantry items reference |
| `created_at`, `updated_at` | `timestamptz` | no | `timestampsTz()` |

**Constraints and indexes**

- `unq_products_slug UNIQUE (slug)` — one global row per slug. PostgreSQL treats `NULL`s as
  distinct, so this places no limit on user additions, which is exactly the intent.
- `unq_products_user_id_name UNIQUE (user_id, name)` — a user cannot declare the same product
  twice. `NULL` user ids are distinct, so it places no limit on the global catalogue; slug covers
  that population. Two overlapping guards, each covering the population the other cannot.
- **No search index.** The catalogue is ~40 rows; a `gin_trgm_ops` index on 40 rows is an object
  the planner will ignore. Add it when the catalogue grows.

**No column anticipates phase 2 or 3.** There is no `season_starts_on`, `season_ends_on`,
`harvest_months`, `price`, `reference_price`, `currency` or `observed_at`. Confirmed against the
column list above: seasonality and price land as purely additive migrations (QA-7).

### M2 — `pantry_items` (per-user)

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `bigserial` | no | |
| `user_id` | `bigint` | no | FK → `users`, `cascadeOnDelete` |
| `product_id` | `bigint` | no | FK → `products`, **`restrictOnDelete`**. A product with pantry history must not vanish; `is_active` is how it retires |
| `quantity` | `numeric(12,3)` | no | Not money. Three decimals carry `0.250 kg`. Never `float` |
| `unit` | `text` | no | Must equal `products.unit` at write time (D4) |
| `acquisition_source` | `text` | no | `AcquisitionSource` value. No DB CHECK |
| `acquired_on` | `date` | no | Defaults to today. Makes "what I keep letting expire" readable |
| `expires_on` | `date` | **yes** | `NULL` = does not expire. Past this date the row stops subtracting and is **kept** (q4) |
| `note` | `text` | yes | Free text, no rule attached |
| `created_at`, `updated_at` | `timestamptz` | no | |

**Indexes**

- `idx_pantry_items_user_product (user_id, product_id)` — the list read's key, sargable.
- `idx_pantry_items_user_expires (user_id, expires_on)` — the "expiring soon" read.

**No unique on `(user_id, product_id)`.** Two purchases of rice with different expiry dates are
two rows; collapsing them would have to discard one expiry date, which is the one field q4 turned
into a rule.

### M3 — `consumption_habits` (per-user)

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `bigserial` | no | |
| `user_id` | `bigint` | no | FK → `users`, `cascadeOnDelete` |
| `product_id` | `bigint` | no | FK → `products`, `restrictOnDelete` |
| `weekly_quantity` | `numeric(12,3)` | no | Declared weekly need |
| `unit` | `text` | no | Must equal `products.unit` at write time |
| `is_active` | `boolean` default `true` | no | Pauses a habit without losing that it was declared |
| `created_at`, `updated_at` | `timestamptz` | no | |

**Constraints**

- `unq_consumption_habits_user_id_product_id UNIQUE (user_id, product_id)` — one declared weekly
  need per product. A second declaration is an update, not a second row, and the arbiter is the
  index rather than a PHP check.
- `idx_consumption_habits_user_active (user_id, is_active)` — the plan reads active habits only.

### M4 — `grocery_budget_links` (per-user, one row)

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `bigserial` | no | |
| `user_id` | `bigint` | no | FK → `users`, `cascadeOnDelete` |
| `category_id` | `bigint` | no | Half of the composite FK below. No single-column FK |
| `resolved_by` | `text` | no | `auto` \| `manual` — how the pin arrived. Name follows `reconciliation_candidates.resolved_by` |
| `linked_at` | `timestamptz` | no | When the pin was taken |
| `created_at`, `updated_at` | `timestamptz` | no | |

**Constraints — the composite FK is raw SQL, because the Schema builder cannot express one**

```sql
ALTER TABLE grocery_budget_links
  ADD CONSTRAINT fk_grocery_budget_links_category_id
  FOREIGN KEY (category_id, user_id) REFERENCES categories (id, user_id)
  ON DELETE CASCADE;
```

- `ON DELETE CASCADE` is the bound decision. Verified why it must be written explicitly: the
  precedent at `2026_08_11_100000_enforce_cross_user_ownership.php:115-119` adds its composite FK
  with **no `ON DELETE` clause at all**, so PostgreSQL applies `NO ACTION` and the delete is
  blocked. Inheriting that would make an unrelated SPA "delete category" fail with a constraint
  error the UI cannot explain. `App\Models\Category` uses only `HasFactory` — **no
  `SoftDeletes`** — so the delete is physical and there is no soft-delete path to fall back on.
- **No new unique on `categories`.** The referenced side is already addressable:
  `unq_categories_id_user_id` was added at line 99 of that same migration.
- `unq_grocery_budget_links_user_id UNIQUE (user_id)` — one pin per user (q7) and the concurrency
  arbiter (D6).
- `idx_grocery_budget_links_category_user (category_id, user_id)` — so deleting a category does
  not scan this table in full to apply the cascade. Same reasoning as
  `idx_transactions_category_user` at line 104 of the precedent.

## The list computation

### Inputs (plain values, no models — `BoundariesTest.php:149-151`)

```php
ConsumptionHabitLine { int $productId; string $productName; Unit $unit; float $weeklyQuantity; }
PantryStock          { int $productId; ?Unit $unit; float $quantity; ?CarbonImmutable $expiresOn; }
GroceryCeilingInput  { int $categoryId; string $categoryName; float $monthlyBudget;
                       BudgetPeriod $budgetPeriod; float $spent; }
PlanningWeek         { CarbonImmutable $asOf; CarbonImmutable $startsOn; CarbonImmutable $endsOn; }
```

`PantryStock::$unit` is nullable on purpose: it holds `Unit::tryFrom()`'s result, so an
unrecognised column value arrives as `null` rather than being coerced (D4).

`PlanningWeek` is Monday-to-Sunday in `America/Lima`, built from `asOf` by the Action (q1). It is
a value object with a named constructor, the `ParetoWindow::forFilter()` shape.

### Rules, in order

1. **Only active habits produce lines.** A pantry item for a product with no habit yields nothing
   — there is nothing to buy that was never declared.
2. **Available** = the sum of `PantryStock::$quantity` for that product where
   `unit === habit.unit` **and** (`expiresOn === null` **or** `expiresOn >= asOf`).
3. **Expired** quantities (`expiresOn < asOf`) are summed into `expiredQuantity` and **subtract
   nothing**. The row is kept, neither deleted nor archived (q4).
4. **Unit mismatch** (`stock.unit !== habit.unit`, including `stock.unit === null`) is summed into
   `unmatchedUnitQuantity`, raises `hasUnitMismatch`, and is **never converted and never netted**.
5. `toBuyQuantity = round(max(0.0, neededQuantity - availableQuantity), 3)`.
6. **`acquisition_source` is never read by the service.** A gift subtracts from availability
   exactly as a purchase does, and the budget side reads transactions — of which a gift produced
   none. There is therefore no branch on the enum anywhere in the arithmetic, which is what makes
   "a gift never touches a `Transaction`" structural rather than a rule someone must remember. The
   column is carried for provenance and reporting, not for computation.
7. Lines are split into two collections so that "already at home" is visible instead of silently
   missing:
   - `lines` — `toBuyQuantity > 0`, sorted by `productName`.
   - `covered` — `toBuyQuantity === 0.0`, each carrying its `neededQuantity` and
     `availableQuantity` so the user can see *why* it dropped off.

   This satisfies the success criterion that a gifted item *removes its product from the list*,
   while keeping the reason legible.

### Output — every line is diagnosable at its source

```php
ShoppingListLine {
    int    $productId;
    string $productName;
    Unit   $unit;
    float  $neededQuantity;         // the habit
    float  $availableQuantity;      // non-expired pantry in the same unit
    float  $expiredQuantity;        // shown, subtracts nothing
    float  $unmatchedUnitQuantity;  // different unit, never converted
    float  $toBuyQuantity;          // = max(0, needed - available)
    bool   $hasUnitMismatch;
    ?CarbonImmutable $soonestExpiryOn;
}

WeeklyShoppingPlan {
    PlanningWeek $week;
    array $lines;    // ShoppingListLine[]
    array $covered;  // CoveredLine[]
    ?GroceryCeilingReading $ceiling;
    string $ceilingState;  // unlinked | unbudgeted | set
    ?string $ceilingResolution; // name_not_found, when applicable
}

GroceryCeilingReading {
    int $categoryId; string $categoryName;
    float $amount; float $spent; float $remaining;
    bool $isExceeded; float $overspend;
    BudgetPeriod $budgetPeriod;
    CarbonImmutable $windowStartsAt; CarbonImmutable $windowEndsAt;
}
```

Carrying all three terms — not just the difference — is what makes the proposal's "a wrong line
is legible and editable at its source" true: the reader sees `needed − available = toBuy` and
knows which of the two numbers to go and fix.

**Known limitation, recorded rather than guessed.** Availability is tested against `asOf`, not
against the week's end, because the owner's decision reads "past `expires_on` it stops counting".
An item expiring mid-week therefore still suppresses its line for the whole week.
`soonestExpiryOn` is on the line so the UI can warn, rather than hiding the effect. Choosing
week-end instead would require deciding *when in the week* an item is consumed, and phase 1 has
no model for that.

## Data flow

```
GET /api/shopping/weekly-plan   (JwtMiddleware)
        │
        ▼
WeeklyPlanController ──────────► BuildWeeklyPlanAction
  (no DB facade, no rule)              │
                                       ├─► PlanningWeek::forDate(Carbon::now())
                                       │
                                       ├─► ShoppingRepositoryContract
                                       │      ├─ activeHabitsFor($userId)   → ConsumptionHabitLine[]
                                       │      └─ pantryStockFor($userId)    → PantryStock[]
                                       │
                                       ├─► ResolveGroceryCategoryAction ──► GroceryBudgetRepositoryContract
                                       │                                      ├─ findLink($userId)
                                       │                                      ├─ findCategoryByExactName(…)  ─┐
                                       │                                      └─ pin(…)  [unq arbiter]        │
                                       │                                                                      │
                                       ├─► GroceryBudgetRepositoryContract::ceilingFor($userId, $window) ◄─────┘
                                       │      ├─ CategoryRepositoryContract::findById($categoryId, $userId)
                                       │      └─ TransactionRepositoryContract::expenseByCategoryBetween(…)
                                       │            (reads v_unified_transactions — reconciled
                                       │             Yape/bank duplicates already excluded)
                                       │      └────► GroceryCeilingInput   (plain DTO — Category never escapes)
                                       │
                                       ▼
                        ShoppingPlanService::plan(habits, pantry, ceiling, week)
                         ── no connection, no model, no clock, no raw query ──
                                       │
                                       ▼
                             WeeklyShoppingPlan → toArray() → response()->json(['data' => …])
```

`lines` and `ceiling` descend from disjoint inputs. That is the q6 guarantee (D5).

## HTTP surface

Every route goes inside the existing authenticated group at `routes/api.php:38`,
`Route::middleware([JwtMiddleware::class])`. Auth is `tymon/jwt-auth`; Sanctum stays untouched
dead weight (technical debt item 9) and this change neither uses nor removes it.

| Method | URI | Controller@method | Slice |
|---|---|---|---|
| GET | `shopping/products` | `ProductCatalogueController@index` | 1 |
| POST | `shopping/products` | `ProductCatalogueController@store` | 1 |
| GET | `shopping/pantry-items` | `PantryItemController@index` | 2 |
| POST | `shopping/pantry-items` | `PantryItemController@store` | 2 |
| PUT | `shopping/pantry-items/{pantryItem}` | `PantryItemController@update` | 2 |
| DELETE | `shopping/pantry-items/{pantryItem}` | `PantryItemController@destroy` | 2 |
| GET | `shopping/consumption-habits` | `ConsumptionHabitController@index` | 3 |
| POST | `shopping/consumption-habits` | `ConsumptionHabitController@store` | 3 |
| PUT | `shopping/consumption-habits/{consumptionHabit}` | `ConsumptionHabitController@update` | 3 |
| DELETE | `shopping/consumption-habits/{consumptionHabit}` | `ConsumptionHabitController@destroy` | 3 |
| GET | `shopping/grocery-budget-link` | `GroceryBudgetLinkController@show` | 4 |
| PUT | `shopping/grocery-budget-link` | `GroceryBudgetLinkController@update` | 4 |
| GET | `shopping/weekly-plan` | `WeeklyPlanController@show` | 5a |

Flat verbs, not `Route::resource`, because the plan is a read model and the link is a singleton —
neither has the seven-action shape. Controllers live in `app/Http/Controllers/Shopping/`, the
`Controllers/Capture/` and `Controllers/Reconciliation/` precedent.

**FormRequests** in `app/Http/Requests/Shopping/`. The load-bearing rule is one composed
`Rule::exists` that enforces visibility *and* the canonical unit in a single scoped subquery —
the `StoreCategoryRequest.php:23-31` trick, which already scopes `exists` to the owner:

```php
'product_id' => ['required', 'integer', Rule::exists('products', 'id')
    ->where(fn ($q) => $q->where('unit', $this->input('unit'))
                         ->where('is_active', true)
                         ->where(fn ($v) => $v->whereNull('user_id')
                                              ->orWhere('user_id', $this->user()?->id)))],
'unit'     => ['required', Rule::enum(Unit::class)],
'quantity' => ['required', 'numeric', 'gt:0'],
'acquisition_source' => ['required', Rule::enum(AcquisitionSource::class)],
'expires_on' => ['nullable', 'date'],
```

`StoreProductRequest` additionally refuses a name that collides case-insensitively with a visible
global product, so a user cannot shadow `Palta` with their own `palta`.
`UpdateGroceryBudgetLinkRequest` scopes `Rule::exists('categories', 'id')->where('user_id', …)`
so a stranger's category can never be pinned.

**No API Resources** (D7).

## The seeded catalogue

**A seeder, not a migration.** `database/seeders/ProductCatalogueSeeder.php`, with the ~40
Peruvian staples as data in `config/shopping.php` under `catalogue`. The precedent is
`config/onboarding.php` consumed by `SeedDefaultWorkspaceAction`, whose own docblock states the
reason: "it is data, and changing a category name should not mean reading control flow." Reference
seeders are also where `FinancialEntitiesSeeder` and `PaymentServicesSeeder` live.

**Idempotent, by upsert on the slug.**

```php
Product::upsert($rows, uniqueBy: ['slug'], update: ['name', 'unit', 'is_active']);
```

`products` is global, so re-running is a deployment operation, not a per-user one. Re-running
updates a corrected name or unit, inserts newly added staples, duplicates nothing, and cannot
touch a user's own additions — those carry `slug = NULL` and `user_id != NULL`, so they are
outside `unq_products_slug` entirely.

**Do not copy `PaymentServicesSeeder`.** Verified: it calls `PaymentService::insert($services)`
with no conflict handling, so running it twice duplicates Yape and Plin. It is the wrong half of
the precedent; the shape to copy is where the data lives, not how it is written.

Deployment runs `php artisan db:seed --class=ProductCatalogueSeeder` after `migrate`. It is
**not** added to `DatabaseSeeder`'s per-user path and **not** called from `UserObserver` — the
catalogue is shared, so seeding it per user would be 40 rows times every registration.

## Frontend — `wajaycha-front-vue`

Layered by technical role. No feature modules, no atomic design. Source of truth is
`wajaycha-front-vue/CLAUDE.md`; the template to copy is `src/views/pareto/` +
`src/composables/usePareto.ts` + `src/repositories/ParetoClassificationRepository.ts` +
`src/interfaces/IPareto.ts` + `test/views/pareto/`.

| Layer | File | Role |
|---|---|---|
| Contracts | `src/interfaces/IShopping.ts` | `IProduct`, `IPantryItem`, `IConsumptionHabit`, `IWeeklyPlan`, `IShoppingListLine`, `ICeilingReading`; `Unit` and `AcquisitionSource` as string-literal unions mirroring the API. Conformist — no renaming of the API's terms |
| Network | `src/repositories/ShoppingRepository.ts` | **The only place that talks to the API.** One class, static methods, `apiClient` from `@/axiosConfig` — the `ParetoClassificationRepository` shape. Never `src/services/`, never `src/api/`, never inside a component |
| State | `src/composables/useWeeklyPlan.ts`, `usePantry.ts`, `useConsumptionHabits.ts` | Per-feature data. Each exposes `loading`, `data` and `error` as explicit refs |
| Container | `src/views/shopping/WeeklyPlanView.vue`, `PantryView.vue`, `ConsumptionHabitsView.vue` | Orchestrate; the only components that call a composable |
| Presentational | `src/views/shopping/components/` — `ShoppingListLineRow.vue`, `CoveredLineList.vue`, `GroceryCeilingStrip.vue`, `PantryItemForm.vue`, `ConsumptionHabitForm.vue`, `ProductPicker.vue` | Props in, events out. No network, no business logic |
| Tests | `test/views/shopping/*.test.ts` | Vitest + `@testing-library/vue`. `await flushPromises()` then `getBy*`, never `findBy*` |

**Pinia gains nothing.** Only `auth` and `theme` are global today and the plan is per-feature
data. Rejected alternative: a `shopping` store — it would make a screen's data application-global
and give two views a shared mutable cache neither asked for.

**`error` is a ref, not only a toast.** `usePareto.ts` catches into `toast.error(...)` and exposes
no error state, so a view cannot render an error path. `wajaycha-front-vue/CLAUDE.md` requires
loading, data *and* error explicitly, so the new composables expose `error` **and** toast. This is
a deliberate improvement on the template, not an oversight in copying it.

**`GroceryCeilingStrip.vue` carries the frontend half of the silent-zero bug.** `ceiling_state`
of `unlinked` must render "elige tu categoría de supermercado" and `unbudgeted` must render
"esta categoría no tiene presupuesto" — **never `S/ 0.00`**. It gets its own Vitest test asserting
that no currency figure is rendered in either state. Modelled on `IncomeAllocationStrip.vue`,
whose `?? 0` comment records the day a missing backend field painted `NaN%` in production.

Tailwind utilities and design tokens only, no hardcoded hex, mobile-first with `md:`/`lg:`.
`ProductPicker` is keyboard reachable and its icon-only controls carry `aria-label`.

**Scope note:** the proposal's "Affected areas" table lists no `wajaycha-front-vue` path. See
Open Questions.

## Subdomain registration and docs

### `openspec/config.yaml`

```yaml
subdomains:
  # … existing six …
  - name: Financial Coaching
    kind: core
  - name: Shopping
    kind: supporting
```

Financial Coaching is added in the same edit. `docs/domain/context-map.md:103` already lists it as
*CORE, planificado*; omitting it from `config.yaml` is pre-existing drift, and the instruction was
not to repeat it — leaving it out while adding a seventh entry beside it would.

Three stale path references in the same file must be corrected in the same slice, because this
design's constraints came from the file that replaced them: `context_sources.repo_rules`
(lines 34-36), `rules.design` (line 106) and `rules.apply.guidelines` (line 114) all point at
`.agents/rules/01-laravel-core.md` and `02-database-dba.md`, which were deleted and consolidated
into `wajaycha-back-laravel/CLAUDE.md`.

### `docs/domain/context-map.md` (Spanish)

A new node inside the `laravel` subgraph, plus a `### Shopping — *de soporte*` section and one
`## Relaciones` row. Every edge points **into** Shopping and is dashed:

```mermaid
SHO["Shopping<br/><i>subdominio de soporte</i>"]

CAT -.->|"categoría fijada por id"| SHO
ANA -.->|"techo de gasto y gasto real"| SHO
IAM -.->|"alcance por usuario"| SHO
```

Shopping has **no outgoing edge to any subdomain**. That absence is the one-way rule drawn rather
than asserted: there is no arrow back to Ingestion or Categorization because no code path writes a
`Transaction`. The `Relaciones` row records Categorization and Financial Analysis as upstream and
the pattern as **Conformist** — Shopping adapts to their existing contracts and asks for no change
to either.

### `docs/domain/ubiquitous-language.md` (Spanish)

Four new terms, each stating what it is **not**, following the shape the existing `Detail` entry
uses — because that entry is the evidence that this is where agents go wrong. Written in Spanish
with identifiers verbatim in English; described here in English, as the language contract
requires.

| Term | Is | Is **not** |
|---|---|---|
| **Product** | A catalogue entry naming a purchasable food item with exactly one canonical `unit` | **Not a `Detail`** — a `Detail` is the normalised counterparty of a movement; a `Product` is a thing in a basket, and Wajaycha stores no basket lines (F1). **Not a `Category`** — a category is a spending bucket with a budget; a product carries no money in phase 1 |
| **Pantry Item** | A quantity of one `Product` a user has at home now, with an `acquisition_source` and an optional `expires_on` | **Never a `Transaction`** and never creates one — a gift and a purchase are both pantry items and neither moves money. **Not a stock ledger** — nothing reconciles it against actual consumption |
| **Consumption Habit** | A user's declared weekly need for one `Product` | **Not inferred, measured or learned** — it is stated by the user and only the user changes it. **Not a budget** — it is expressed in units, never in soles |
| **Grocery Budget Link** | The pinned `category_id` a user's grocery ceiling is read from | **Not the category's name.** Renaming `🛒 Supermercado` changes nothing once the pin exists |

### `docs/domain/data-dictionary.md`

Regenerated with `./scripts/generate-data-dictionary.sh` after the migrations run. Never
hand-edited. This is why every column above carries a Spanish `comment(…)`.

## File changes

| File | Action | Description |
|---|---|---|
| `database/migrations/…_create_products_table.php` | Create | M1 |
| `database/migrations/…_create_pantry_items_table.php` | Create | M2 |
| `database/migrations/…_create_consumption_habits_table.php` | Create | M3 |
| `database/migrations/…_create_grocery_budget_links_table.php` | Create | M4 + raw composite FK |
| `config/shopping.php` | Create | `grocery_category_name`, `catalogue` |
| `database/seeders/ProductCatalogueSeeder.php` | Create | Idempotent upsert on `slug` |
| `app/Enums/Unit.php`, `app/Enums/AcquisitionSource.php` | Create | Closed enums, `values()`, no collaborators |
| `app/Models/Product.php`, `PantryItem.php`, `ConsumptionHabit.php`, `GroceryBudgetLink.php` | Create | `HasFactory`, `$fillable`, relations. No service calls (`BoundariesTest.php:160-162`) |
| `database/factories/…` | Create | One per model, for the Feature tests |
| `app/DTOs/Shopping/*.php` | Create | The nine DTOs listed above. No `App\Models` import |
| `app/Repositories/Contracts/ShoppingRepositoryContract.php` | Create | Own tables |
| `app/Repositories/Contracts/GroceryBudgetRepositoryContract.php` | Create | Pin + composed ceiling read |
| `app/Repositories/ShoppingRepository.php`, `GroceryBudgetRepository.php` | Create | Flat, matching the existing layout |
| `app/Services/Shopping/ShoppingPlanService.php` | Create | The owning service. Pure |
| `app/Actions/Shopping/*.php` | Create | `BuildWeeklyPlanAction`, `ResolveGroceryCategoryAction`, and the CRUD actions |
| `app/Http/Controllers/Shopping/*.php` | Create | Five thin controllers |
| `app/Http/Requests/Shopping/*.php` | Create | Seven FormRequests |
| `app/Providers/AppServiceProvider.php` | Modify | Bind both new contracts, following lines 19-49 |
| `routes/api.php` | Modify | Thirteen routes inside the `JwtMiddleware` group |
| `tests/Unit/Architecture/BoundariesTest.php` | Modify | Add `App\Services\Shopping` to the `toUseStrictTypes()` list at line 197, so the new namespace's convention is executable rather than aspirational |
| `openspec/config.yaml` | Modify | Shopping + Financial Coaching subdomains; three stale `.agents/rules` paths |
| `../docs/domain/context-map.md` | Modify | Shopping node, read-only edges, `Relaciones` row (Spanish) |
| `../docs/domain/ubiquitous-language.md` | Modify | Four terms (Spanish) |
| `../docs/domain/data-dictionary.md` | Regenerate | Script only |
| `../wajaycha-front-vue/src/**`, `test/views/shopping/**` | Create | See the frontend table |
| `CLAUDE.md` | Modify | Correct the stale "No migration uses `fk_`" sentence (D9) |

## Testing strategy

Pest 3 on PHPUnit 11, `php artisan test`, against **real PostgreSQL `wajaycha-1`** with pgvector
and pg_trgm. `config/database.php` defaults to `sqlite`, which is a documented trap and is
disqualifying here specifically: composite foreign keys, `ON DELETE CASCADE`, `NULL`s-are-distinct
unique semantics and `timestamptz` are all PostgreSQL behaviours sqlite would silently fake, so
the schema tests would pass while proving nothing. `RefreshDatabase` on `Feature` only.

| Layer | Location | What | Approach |
|---|---|---|---|
| Unit | `tests/Unit/Shopping/ShoppingPlanServiceTest.php` | Every rule: habit−pantry netting, the expiry cut at `asOf`, expired quantity shown but not subtracted, unit mismatch never converted, `covered` vs `lines` split, the three ceiling states, exceeded ceiling leaving `lines` identical, `MONTHLY` vs `YEARLY` window | Fixtures only. **No database, no `RefreshDatabase`, no booted application** — the `ParetoReportBuilderTest` mould |
| Unit | `tests/Unit/Shopping/PlanningWeekTest.php` | Monday-to-Sunday boundaries in `America/Lima`, including a Sunday `asOf` | Pure |
| Unit | `tests/Unit/Architecture/BoundariesTest.php` | `App\Services\Shopping` declares strict types; the existing seven rules keep passing untouched | `arch()` + the source scan |
| Feature | `tests/Feature/Shopping/ShoppingSchemaTest.php` | Column types, nullability, the named constraints, `numeric` not `float`, `timestamptz` | The `CoachingObservationSchemaTest` precedent |
| Feature | `tests/Feature/Shopping/GroceryBudgetLinkCascadeTest.php` | Deleting the pinned category succeeds and removes the pin; the capability returns to `unlinked` | Real cascade, real PostgreSQL |
| Feature | `tests/Feature/Shopping/GroceryCeilingReadTest.php` | No link → `unlinked`; pinned with `monthly_budget = 0` → `unbudgeted`; pinned and budgeted → `set` with spend from `v_unified_transactions`; a reconciled Yape/bank pair counted once | Through `GroceryBudgetRepositoryContract` |
| Feature | `tests/Feature/Shopping/GroceryCategoryResolutionTest.php` | Resolves once and pins; never reads the name again; a renamed category yields `name_not_found` and writes nothing; a second concurrent pin attempt is absorbed by the unique index | Real index |
| Feature | `tests/Feature/Shopping/ProductCatalogueSeederTest.php` | Running it twice inserts no duplicate and leaves user-owned products untouched | Seeder run twice |
| Feature | `tests/Feature/Shopping/*EndpointTest.php` | 13 routes behind `JwtMiddleware`; 401 unauthenticated; 422 on unit mismatch; 422 on a unit the product does not declare | HTTP |
| Feature | `tests/Feature/Security/CrossUserAccessTest.php` | **Modify.** Another user's `pantry_items` / `consumption_habits` id returns **404**, and a stranger's `category_id` cannot be pinned | Existing suite extended |
| Feature | `tests/Feature/Database/CrossUserOwnershipTest.php` | **Must pass unchanged** — no assertion of it is modified | Regression |
| Frontend unit | `test/views/shopping/GroceryCeilingStrip.test.ts` | `unlinked` and `unbudgeted` render a prompt and **no currency figure** | Vitest + Testing Library |
| Frontend unit | `test/views/shopping/ShoppingListLineRow.test.ts`, `WeeklyPlanView.test.ts` | `needed − available` visible per line; loading / data / error paths all render | `flushPromises()` then `getBy*` |

Static analysis `./vendor/bin/phpstan analyse` (Larastan level 6) and `./vendor/bin/pint --test`
gate every slice. **Strict TDD is active:** every behavioural unit above is a RED test written
before its implementation.

## Threat matrix

Recorded per row rather than waved off, and every row is `N/A`: this change adds authenticated
HTTP routes and four tables, and touches no shell command, subprocess, VCS or PR automation, and
no executable-file classification.

| Boundary | Applicability | Reason |
|---|---|---|
| Documentation-like paths | **N/A** | No file is classified or executed by this change. The only generated artefact is `docs/domain/data-dictionary.md`, produced by an existing script this change does not modify |
| Git repository selection | **N/A** | No `git` invocation. The change writes no VCS automation |
| Commit state | **N/A** | No index or worktree manipulation |
| Push state | **N/A** | No ref resolution or push path |
| PR commands | **N/A** | No PR automation, no composed shell commands |

The adversarial surface this change *does* have is authorization, and it is designed for rather
than added to the matrix: `$userId` is an authorization boundary on every repository method (D8),
the composite FK makes a cross-user pin unrepresentable in the schema (M4), FormRequests scope
every `exists` to the owner, and both the existing `CrossUserAccessTest` and
`CrossUserOwnershipTest` are named above as gates. The one accepted weakness — a plain
single-column FK on `pantry_items.product_id` — is stated with its bound in D3.

## Migration and rollout

All four migrations are purely additive: no existing column, constraint, row, SQL function or
view is modified. Nothing touches queues, Horizon or the Gemini integration.

| Order | Step |
|---|---|
| 1 | `php artisan migrate` |
| 2 | `php artisan db:seed --class=ProductCatalogueSeeder` — idempotent, safe to repeat |
| 3 | `./scripts/generate-data-dictionary.sh` |

No feature flag. The capability is inert until a user declares a habit: with no habits the plan
returns empty `lines` and `covered`, and the ceiling reports `unlinked`. To disable it without a
deploy, delete the pin row — the list still computes and the ceiling reports `unlinked`.

| Slice | Revert |
|---|---|
| 1 | `DROP TABLE products`. Enum, config, seeder and docs are code and text only |
| 2 | `DROP TABLE pantry_items`. `products` unaffected |
| 3 | `DROP TABLE consumption_habits` |
| 4 | `DROP TABLE grocery_budget_links`. `categories` is untouched — the pin was only ever a reference, so dropping it loses the link and no budget data |
| 5a / 5b | Revert the commit. The plan was never stored |

Safe revert order is 5 → 4 → 3 → 2 → 1, the reverse of the FK direction.

## Slice validation

The five slices were checked against the concrete layering above. **The boundaries hold, with one
correction and one split.**

| # | Slice | Verdict | Revised |
|---|---|---|---|
| 1 | `products` + `Unit` + catalogue read + subdomain registration + three doc amendments | **Holds, but grows.** `products` now carries nullable `user_id` and two-guard uniqueness (D3), `POST /shopping/products` is real work q3 implies but exploration never costed, and the three stale `config.yaml` path corrections land here | ~380 |
| 2 | `pantry_items` + `AcquisitionSource` + CRUD | **Holds.** Depends only on slice 1. The composed unit/visibility `Rule::exists` and the no-route-model-binding resolution pattern are both paid for here first | ~340 |
| 3 | `consumption_habits` + CRUD | **Holds, and shrinks.** Both patterns slice 2 established are reused verbatim | ~220 |
| 4 | `grocery_budget_links` + resolve-then-pin + composed ceiling read | **Holds.** One qualification: it is independently *valuable* and independently *verifiable* — it fixes the silent-zero class and is the schema-affecting F3 fix — but it is not independently *user-visible*, since it ships only `GET`/`PUT /shopping/grocery-budget-link`. That is acceptable; it should not be sold as a user-facing slice | ~320 |
| 5 | Pure builder + owning service + endpoint | **Does not hold at 330.** The frontend — one interfaces file, one repository, three composables, three views, six components and the Vitest tests — was never counted in the ~1,490. Split into **5a** backend plan (`ShoppingPlanService`, `BuildWeeklyPlanAction`, `GET /shopping/weekly-plan`) and **5b** frontend | 5a ~300, 5b ~380 |

**Revised forecast: six slices, ≈ 1,940 authored lines.** Every slice still exceeds the 400-line
budget; slice 3 is the closest and still 220 over nothing that fits. `sdd-tasks` must re-forecast
against this design rather than inherit the proposal's number.

```
Decision needed before apply: Yes
Chained PRs recommended: Yes
400-line budget risk: High
```

`delivery_strategy` is `ask-on-risk`, so the orchestrator stops and asks before apply. Follow the
`financial-coaching-clarify` precedent: stacked commits on one branch, not a branch per slice.

## Open questions

- [ ] **Is the frontend in scope of this change at all?** The proposal's "Affected areas" table
      lists no `wajaycha-front-vue` path, yet the design brief asked for a frontend design. Either
      the proposal's table is incomplete or the SPA is a follow-up change. Designed above so
      `sdd-tasks` is not blocked, but the answer changes the slice count from six back to five and
      the estimate from ~1,940 to ~1,560. **The orchestrator should put this to the owner before
      apply.**
- [ ] **Does an item expiring mid-week still suppress its line for the whole week?** The design
      says yes, from the owner's "past `expires_on`" wording, and surfaces `soonestExpiryOn` so
      the effect is visible. A week-end test instead would need a within-week consumption model.
      Not blocking.
- [ ] **Should a user-created product be rejected when its name collides with a visible global
      one?** The design rejects it case-insensitively. The looser alternative — allow it, mark it
      "mine" in the picker — is also defensible. Not blocking; it is one FormRequest rule either
      way.
