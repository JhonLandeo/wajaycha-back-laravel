# Exploration — Weekly grocery planning

- **Date:** 2026-09-26
- **Scope explored:** managing the weekly market/grocery shop for a user in Peru — what to buy,
  what is already at home, and what the budget allows. Seasonality and live price data were
  explored only far enough to keep them out; they are deferred to later changes.
- **Method:** direct reading of the repository. Every claim below is marked `verified` (read in
  the code) or `inferred`.
- **Artifact store:** `openspec`. The same content also exists in Engram as
  `sdd/grocery-planning/explore` (observation 308), written by the explore phase, which has no
  file-write tool; this file is the orchestrator's write-back.

## The idea as stated

The owner wants to plan the weekly market shop, with two specific requirements:

1. Steer buying toward what is cheap or in season **in Peru**.
2. Count products already at home — including things sent by family — so they are not bought
   twice.

## The decided relationship with Wajaycha

The owner chose a **one-way, read-only** relationship: **Wajaycha feeds the plan; this
capability never writes a `Transaction` back.**

That choice is what keeps the change tractable, and the reason is structural rather than
stylistic — see F1.

## Findings

### F1 — Wajaycha stores no basket lines, so it can feed the budget but never the list `verified` · SEVERITY: highest

A `Transaction` (`app/Models/Transaction.php`) carries a total `amount`, a `date_operation` and
a `detail_id`. `Detail` (`app/Models/Detail.php`) is the normalised **counterparty**, never a
basket line. This is not an omission; it is normative in three places:

| Where | What it says |
|---|---|
| `openspec/specs/ingestion/spec.md:9` | "`Detail` is the normalised counterparty, not a line item, and one `Detail` has many `Transaction`s" |
| `openspec/config.yaml:99` | the same rule, restated as a spec-authoring constraint |
| `docs/domain/ubiquitous-language.md:35-39` | warns that any agent treating `Detail` as a per-transaction attribute will model it wrong |

The receipt parser confirms the intent behaviourally: `app/DTOs/WhatsApp/ParsedReceiptDTO.php:9-17`
extracts only `isValid, amount, destination, origin, dateOperation, type, message`. It reads
photographs of receipts through `GeminiVisionService` and deliberately does not extract the items.

**Consequence.** Transaction history can answer "how much do I spend on groceries" and never
"what is in my basket". Basket composition must originate inside this capability. A product or
pantry concept cannot reuse `Detail`.

### F2 — The budget read needs no new repository method `verified`

`TransactionRepositoryContract::expenseByCategoryBetween(int $userId, CarbonImmutable $startsAt, CarbonImmutable $endsAt): array`
already exists (contract at `app/Repositories/Contracts/TransactionRepositoryContract.php:81`,
implementation at `app/Repositories/TransactionRepository.php:178`). It reads
`v_unified_transactions` (`:185`), so reconciled Yape/bank duplicates are already excluded.
`app/Services/Coaching/SpendingRhythmService.php:49` and `MonthOverMonthService.php:45` consume
it today.

The ceiling side is `categories.monthly_budget` plus `categories.budget_period`, typed by
`App\Enums\BudgetPeriod` (`MONTHLY|YEARLY`). That enum is not a display preference: its own
documentation distinguishes a *ritmo* (continuously spent, month is the natural unit) from a
*sobre* (an annual amount consumed in jumps, where linear projection is noise). Groceries are a
*ritmo*, which is the case the existing arithmetic already serves.

### F3 — Identifying the grocery category by name repeats an anti-pattern the codebase already documents `verified` · SEVERITY: high · **CRITICAL, unresolved**

The leaf category `🛒 Supermercado` is seeded per user at `config/onboarding.php:69`, under
parent `🍽️ Alimentación`, in Pareto band `Variables`, by
`app/Actions/Users/SeedDefaultWorkspaceAction.php:35-45` via `App\Observers\UserObserver`.

Nothing pins it. There is no slug and no unique-name constraint; the user can rename or delete
it from the SPA. Matching by name at read time therefore fails silently — the capability would
report a zero budget rather than an error.

The codebase already warns about the structurally identical mistake with integers.
`app/Models/FinancialEntity.php` documents `BCP_ID` as carrying "the same caveat as
`PaymentService::YAPE_ID`: it is an assumption about seeded ids, not a fact the schema enforces."
Matching a seeded category by string is that same assumption, weaker.

**Precedent, with a correction.** `category_pareto_assignments`
(`database/migrations/2026_04_20_000000_decouple_pareto_from_categories.php:18-25`) is the right
*shape*: an explicit table linking a concept to a category by id, one row per category, commented
as maintaining the link "for decoupling". But its FK is the older plain style —
`foreignId('category_id')->unique()->constrained()`, with no `user_id`. A new table must instead
use the composite `(category_id, user_id)` pattern introduced later by
`database/migrations/2026_08_11_100000_enforce_cross_user_ownership.php` and guarded by
`tests/Feature/Database/CrossUserOwnershipTest.php`. Copy the shape, not the FK.

**`sdd-propose` must decide** the pinning mechanism and its timing (seed-time extension of
`SeedDefaultWorkspaceAction`, or lazy-resolve-then-pin on first use). It is schema-affecting and
cannot be left implicit.

### F4 — The consumption model is the product decision that decides whether this is useful `inferred` · **CRITICAL, unresolved**

For a list to be *derived*, the system must know what the user habitually consumes. Nothing in
the repository can supply that, because of F1. Three realistic options:

| Option | Verdict |
|---|---|
| Explicit declared habit (`product_id` + weekly quantity/unit) | **Recommended.** Deterministic, works on day one. Cost: the user hand-enters their staples once. |
| Inferred from repeated pantry depletion | Rejected for phase 1. Cold-start — no history exists — and it depends on a settled unit-conversion model this phase deliberately does not build. |
| Recurring list without quantities | Rejected. Without quantities there is nothing to net against the pantry, which defeats the "don't buy what I already have" requirement outright. |

The proposal must state plainly that manual setup is required. There is no honest way to
bootstrap it from existing data.

### F5 — The shopping list should not be a table `inferred`

The list is a function of (declared habit − current pantry). Persisting it introduces a staleness
problem with no compensating benefit: buying an item means logging a `PantryItem`, which already
removes it from the next computed list.

This mirrors the pattern the codebase already rewards. `ParetoReportBuilder` and `PaceEvaluator`
take pure values in and return decisions out, holding no connection and no model; the repository
contract feeds them. `app/Repositories/ParetoRepository.php` plus `BuildParetoReportAction` is
the wiring to copy.

### F6 — Aggregate shapes follow two existing precedents, not one `inferred`

- **`products`** — global reference data, no `user_id`, matching the `FinancialEntity` /
  `PaymentService` shape rather than the per-user `categories` / `details` shape.
- **`pantry_items`** — per-user. `product_id` is a plain FK because `products` is global.
  Carries `acquisition_source`, quantity, unit and a nullable expiry.
- **`consumption_habits`** — per-user, per F4.

**Net new tables for phase 1: three.** Seasonality (phase 2) and price observations (phase 3)
must not appear now, and `products` must stay free of their columns so they land later as purely
additive migrations.

### F7 — `acquisition_source` must be an enum, not a note `inferred`

A gifted item has two opposite effects: it **subtracts from the shopping list** (it is already
at home) and **does not subtract from the budget** (it cost nothing). Modelled as free text,
both behaviours are lost.

A small closed enum (`purchased | gift | harvested`) follows the `BudgetPeriod` / `ImportStatus`
precedent: string column, no database CHECK, validated in the FormRequest.

**Do not conflate this with the `🎁 Regalos Recibidos` income category**
(`config/onboarding.php:39`). A gifted pantry item must never touch a `Transaction` — that is
what the one-way decision means.

### F8 — Units need an enum and no conversion table `inferred`

Peruvian grocery reality mixes kg, g, l, ml, `unidad`, `atado` and `paquete`. For phase 1 each
`Product` pins one canonical unit and a mismatch is a validation error, never a silent
conversion. A conversion table is a later change behind the same enum.

### F9 — This is a new logical subdomain, not an extension `inferred`

`docs/domain/context-map.md:5-13` states there is one bounded context with persistence and that
the subdomains inside it are logical, not physically separated. The seven existing subdomains are
Ingestion, Entity Resolution, Categorization, Financial Analysis, Financial Coaching,
Notification and Access.

None owns grocery planning. It consumes Financial Analysis's judgment rather than producing it,
so `kind: supporting` fits. This requires a new entry under `subdomains:` in
`openspec/config.yaml` and a new node with read-only edges in `docs/domain/context-map.md`.

Note a pre-existing drift to avoid repeating: `openspec/config.yaml` does not list Financial
Coaching either, although the context map does.

### F10 — Do not model on top of a dead path `verified`

`KeywordRule` has no write path anywhere — no route, controller, seeder or factory creates one —
so steps 3 and 4 of the cascade in `app/Services/CategorizationService.php` are unreachable in
production (`docs/README.md:50-54`). Recorded so no part of this capability is built on it.

## Coupling boundary

This capability's own repository contract should **compose** the existing contracts —
`CategoryRepositoryContract` for the pinned category and
`TransactionRepositoryContract::expenseByCategoryBetween()` for actual spend — and translate the
result into a plain DTO before the owning service sees it. No new raw queries against `categories`
or `transactions`, and no reaching into Categorization's or Financial Analysis's internals.

This is also what the architecture tests enforce: no service may run `DB::select|table|raw|
statement|insert|update|delete` (`tests/Unit/Architecture/BoundariesTest.php:116-138`), and a DTO
may not import `App\Models` (`:149-151`).

## Risks

| Risk | Severity |
|---|---|
| **400-line review budget will be exceeded.** Three tables plus repository, DTOs, service, action and controller, doubled by strict-TDD pairing. Suggested slices: (1) product catalogue, (2) pantry item, (3) consumption habit, (4) budget read + list builder. | High |
| **`docs/domain/ubiquitous-language.md` must be amended** to introduce product, pantry item and consumption habit. That file is Spanish by owner decision; this artifact is English. | Certain |
| Category identification (F3) is unresolved and schema-affecting. | CRITICAL |
| Consumption model (F4) is unresolved and affects both schema and onboarding UX. | CRITICAL |
| `🧹 Artículos de Limpieza` (`config/onboarding.php:60`) is adjacent but out of a food-only phase 1. Avoid hardcoding food-only in a way that blocks later broadening, but do not seed non-food products now. | Low |

## Out of scope

| Item | Disposition |
|---|---|
| Seasonality windows | Phase 2. A machine-readable national harvest calendar exists and is cheap; see the change that follows. |
| Live price data | Phase 3. Genuinely new external-integration class — no scraping or non-AI outbound HTTP exists in `app/` today. |
| Writing `Transaction`s from the plan | Excluded by the owner's one-way decision. |
| Extracting basket lines from receipt photos | Excluded — would require amending the `Detail` rule in F1. |
| Unit conversion | Later change, behind the F8 enum. |

## Ready for proposal

Yes, provided `sdd-propose` explicitly binds two decisions: the category-pinning mechanism and
its timing (F3), and confirmation of the explicit-habit consumption model (F4).
