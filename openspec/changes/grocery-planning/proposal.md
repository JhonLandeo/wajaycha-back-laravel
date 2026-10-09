# Proposal — Weekly grocery planning

- **Change:** `grocery-planning`
- **Date:** 2026-09-26
- **Owning subdomain:** Shopping — **new logical subdomain**, `kind: supporting`
- **Also reads:** Categorization (`categories`), Financial Analysis (`v_unified_transactions`)
- **Prior exploration:** [`exploration.md`](./exploration.md) — findings F1-F10 are the input and are not re-derived here

## Problem

The owner plans the weekly market shop from memory. Two failures follow: buying something
already at home (including food sent by family), and discovering the grocery overspend only
after the month closes.

Wajaycha cannot answer either question today. A `Transaction` carries a total and a
counterparty, never a basket line (F1 — normative in `openspec/specs/ingestion/spec.md:9`,
`openspec/config.yaml:99` and `docs/domain/ubiquitous-language.md:35-39`). So it knows *how
much* was spent on groceries and can never know *what was bought*.

**Outcome:** before going to the market, the user opens one list that already subtracts what
is in the pantry and shows how much of the grocery budget the month has left.

## Scope

| In scope (phase 1) | Out of scope |
|---|---|
| `products` — canonical catalogue, global | Seasonality windows → phase 2 |
| `pantry_items` — per-user, with `acquisition_source` | Live price data → phase 3 |
| `consumption_habits` — per-user, declared | Writing a `Transaction` from a plan |
| `grocery_budget_links` — the pinned category (see below) | Basket lines from receipt photos |
| Weekly list **computed on read**, not stored | Unit conversion |
| Spend ceiling read from the pinned `monthly_budget` | Non-food household goods |

**The relationship with Wajaycha is one-way and read-only.** Wajaycha feeds the plan; this
capability never writes a `Transaction`. That is the constraint that keeps phase 1 tractable.

## Capabilities

### New Capabilities
- `shopping`: product catalogue, pantry state, declared consumption habits, the computed
  weekly list, and the grocery budget link.

### Modified Capabilities
None. Categorization and Financial Analysis are consumed through their existing contracts;
no requirement of theirs changes.

## Bound decision — how the grocery category is identified

**Lazy-resolve-then-pin.** On first use, resolve `🛒 Supermercado` by name **once**, persist
the `category_id` in `grocery_budget_links`, and never consult the name again.

Rejected alternative: pinning inside `SeedDefaultWorkspaceAction` at seed time. It is not
wrong, but it only covers users seeded after the change and needs a backfill migration for
every existing user — the owner is one of them. Lazy resolution covers both populations with
one mechanism and confines name-matching to a single user-visible moment instead of every
read. Without a pin, the capability would repeat the assumption `app/Models/FinancialEntity.php`
already documents against itself: `BCP_ID` "is an assumption about seeded ids, not a fact the
schema enforces."

| State | Behaviour |
|---|---|
| **No link yet** | The list is still computed (habit − pantry). The ceiling is returned **absent, never zero.** A zero ceiling is a lie that reads as "you have no budget"; absent reads as "link a category". |
| **Resolution finds no match** | Same absent state, plus a prompt to choose the category explicitly. Renaming the category is legitimate and must not be an error. |
| **Link exists** | `monthly_budget` and `expenseByCategoryBetween()` are read by id. The name is irrelevant from then on. |
| **User deletes the pinned category** | The pin's composite FK carries `ON DELETE CASCADE`, so the pin disappears with the category and the capability returns to the no-link state. `ON DELETE RESTRICT` — the default, and what `2026_08_11_100000_enforce_cross_user_ownership.php` uses for `transactions` — would instead make an unrelated SPA action fail with a constraint error the UI cannot explain. `categories` is hard-deleted; there is no soft delete to fall back on. |

## Tables and why each has its ownership shape

| Table | Ownership | Reason |
|---|---|---|
| `products` | **Global**, no `user_id` | Reference data, like `FinancialEntity` and `PaymentService`. "Palta" is the same product for every user. One canonical `unit` per product. Must stay free of seasonality and price columns so phases 2-3 land as purely additive migrations (F6). |
| `pantry_items` | Per-user | What is at home is private. `product_id` is a plain FK because `products` is global. Carries quantity, unit, `acquisition_source`, nullable expiry. |
| `consumption_habits` | Per-user | A declared habit is personal. `product_id` + weekly quantity + unit. |
| `grocery_budget_links` | Per-user, one row per user | Copies the *shape* of `category_pareto_assignments` — an explicit table linking a concept to a category by id — but **not its FK.** That table uses the older plain `foreignId('category_id')->unique()->constrained()` with no `user_id`. This one uses the composite `(category_id, user_id) → categories (id, user_id)` pattern guarded by `tests/Feature/Database/CrossUserOwnershipTest.php`. |

Money stays `numeric(15,2)`, timestamps `timestamptz`, quantities `numeric`; every table and
principal column carries `$table->comment(...)` because that text feeds the generated
`docs/domain/data-dictionary.md`.

## The list is computed, not persisted

The list is a pure function of (declared habit − current pantry). Persisting it buys nothing
and costs a staleness problem: buying an item means logging a `PantryItem`, which already
removes it from the next computed list (F5).

This copies the shape the repository already rewards — `ParetoReportBuilder` and
`PaceEvaluator` take values in and return decisions out, holding no connection and no model,
with `ParetoRepository` + `BuildParetoReportAction` as the wiring.

## `acquisition_source` is a first-class enum

`purchased | gift | harvested`, as a small closed PHP enum on a string column with no DB CHECK,
validated in the FormRequest — the `App\Enums\BudgetPeriod` / `ImportStatus` precedent.

It must be an enum because a gifted item has **two opposite effects**: it subtracts from the
shopping list (it is already at home) and does **not** subtract from the budget (it cost
nothing). As free text both behaviours are lost.

- A pantry item **never** touches a `Transaction`, whatever its source. That is what the
  one-way decision means.
- It is **not** the `🎁 Regalos Recibidos` income category (`config/onboarding.php:39`). That
  category records money received. This records food received. Conflating them would inflate
  income by the value of a gift that was never money.

## Units — enum, no conversion

Each `Product` pins one canonical unit (`kg | g | l | ml | unidad | atado | paquete`). A pantry
item or habit in a different unit is a **validation error**, never a silent conversion (F8). A
conversion table is a later change behind the same enum.

## Coupling boundary

`ShoppingRepositoryContract` **composes** the existing contracts —
`CategoryRepositoryContract` for the pinned category and
`TransactionRepositoryContract::expenseByCategoryBetween()` for actual spend — and returns plain
DTOs before the owning service sees them.

- **No new repository method is needed for the spend read** (F2). `expenseByCategoryBetween()`
  reads `v_unified_transactions`, so reconciled Yape/bank duplicates are already excluded.
- No new raw query against `categories` or `transactions`; no new PostgreSQL function (ADR-0009).
- One owning service, `App\Services\Shopping\ShoppingPlanService`; the pure builder beside it.
  Sub-namespaces in the flat `app/`, no physical module folders (ADR-0005).

## Affected areas

| Area | Impact | What changes |
|---|---|---|
| `database/migrations/` | New | Four additive tables |
| `app/Models/`, `app/Enums/`, `app/DTOs/Shopping/` | New | `Product`, `PantryItem`, `ConsumptionHabit`, `Unit`, `AcquisitionSource` |
| `app/Repositories/Shopping*` | New | Composes existing contracts |
| `app/Services/Shopping/` | New | Owning service + pure list builder |
| `app/Actions/Shopping/`, `app/Http/Controllers/` | New | Orchestration only |
| `openspec/config.yaml` | Modified | `subdomains:` entry for Shopping. Note the file does not list Financial Coaching either — a pre-existing drift **not** to repeat |
| `docs/domain/context-map.md` | Modified | New Shopping node, read-only edges to Categorization and Financial Analysis |
| `docs/domain/ubiquitous-language.md` | Modified | New terms: **Product** (catalogue entry with one canonical unit, not a `Detail`), **Pantry Item** (a quantity at home with a source, never a `Transaction`), **Consumption Habit** (declared weekly need). Each must state what it is *not*, because the existing `Detail` entry shows that is where agents go wrong. **This file is Spanish by owner decision; the edit is written in Spanish, described here in English.** |
| `docs/domain/data-dictionary.md` | Regenerated | `./scripts/generate-data-dictionary.sh`, never by hand |

## Drivers

| Driver | Rank | How this change serves it |
|---|---|---|
| **QA-3** integrity | 2 | The pin replaces name-matching, which fails silently; an absent ceiling is never reported as zero |
| **QA-4** safety | 1 | One-way by construction: no code path writes a `Transaction` |
| **QA-1** friction | 4 | What the change *costs* — see the manual setup risk below |
| **QA-7** modifiability | 5 | `products` stays free of seasonality and price columns so phases 2-3 are additive |

## Delivery forecast

**400-line budget risk: High** — confirmed, not inherited. `delivery_strategy` is
`ask-on-risk`, so **the orchestrator must stop and ask before apply.**

`Decision needed before apply: Yes`
`Chained PRs recommended: Yes`
`400-line budget risk: High`

Exploration proposed four slices. Revised to **five**: the budget link is split from the list
builder, because the link is the schema-affecting F3 fix and is independently valuable — it
repairs a silent-zero bug class on its own — while the builder is pure logic with no migration.

| # | Slice | Kind | Est. lines |
|---|---|---|---|
| 1 | `products` + `Unit` enum + catalogue read; subdomain registration and the three doc amendments | schema + docs | ~300 |
| 2 | `pantry_items` + `AcquisitionSource` enum + CRUD | schema + behaviour | ~320 |
| 3 | `consumption_habits` + CRUD | schema + behaviour | ~240 |
| 4 | `grocery_budget_links` + lazy-resolve-then-pin + composed spend/ceiling read | schema + behaviour | ~300 |
| 5 | Pure weekly-list builder + owning service + endpoint | behaviour | ~330 |

Total ≈ **1,490 authored lines**, roughly doubled from the production count by strict-TDD
pairing (`openspec/config.yaml:64`). No single slice fits 400 lines comfortably; slices 3 and 4
are the closest. Follow the precedent set by `financial-coaching-clarify`: stacked commits on
one branch, not a branch per slice.

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| **Manual setup is unavoidable.** Roughly 20-40 staples entered once, then maintained as habits change. Nothing in the repository can bootstrap it (F1, F4) | Certain | State it before building, not after. Alternatives — inference from pantry depletion, a quantity-less list — were offered and rejected by the owner. Mitigate with a seeded `products` catalogue so the user picks rather than types, and accept that habits themselves must be declared |
| A stale habit silently corrupts the list | Med | The list shows *why* each line is there (habit quantity − pantry quantity), so a wrong line is legible and editable at the source |
| The pantry is only as good as the user's logging discipline | High | Out of reach in phase 1. The expiry field and the computed list give a reason to keep it current; nothing enforces it |
| Five slices is a long chain | High | `ask-on-risk` stops before apply; slices 1-3 are independent and each is useful alone |
| `docs/` amendments drift from the code | Med | Data dictionary is generated; the subdomain entry lands in slice 1, not at the end |
| `🧹 Artículos de Limpieza` is adjacent but excluded | Low | Do not hardcode food-only in a way that blocks broadening; do not seed non-food products now |

## Rollback

All four migrations are **purely additive** — no existing column, constraint or row is
modified. Nothing touches queues or the Gemini integration.

| Slice | Revert |
|---|---|
| 1 | `DROP TABLE products`, revert the commit. The enum and docs are code and text only |
| 2 | `DROP TABLE pantry_items`. `products` is unaffected |
| 3 | `DROP TABLE consumption_habits` |
| 4 | `DROP TABLE grocery_budget_links`. `categories` is untouched — the pin was only ever a reference to it, so dropping it loses the link and no budget data |
| 5 | Revert the commit. No persisted state depends on it; the list was never stored |

Reverting out of order is safe in one direction only: drop 5 → 4 → 3 → 2 → 1. To disable the
capability without a deploy, remove the pin row — the list still computes and the ceiling
reports absent.

## Open questions

Each carries the answer the proposal assumes. None blocks `sdd-spec` or `sdd-design`.

| # | Question | Assumed |
|---|---|---|
| q1 | Which week does "weekly" mean? | Monday-to-Sunday, the user's local week. Not a rolling 7 days, because the shop is a fixed weekly event |
| q2 | Is the seeded `products` catalogue curated by us or built by the user? | We seed a small Peruvian staples list (~40 products) so the first list is possible without typing; the user may add their own |
| q3 | Can the user add a product the catalogue lacks? | Yes. Otherwise the catalogue becomes a ceiling on the product |
| q4 | Does an expired pantry item still subtract from the list? | No. Past `expires_on` it stops counting as available, but the row is kept rather than deleted |
| q5 | Does the list show money at all, or only quantities? | Quantities plus the remaining ceiling. Per-line cost needs prices, which is phase 3 |
| q6 | When the ceiling is already exceeded, does the list change? | No. It states the overspend and still shows what is needed. Suppressing needed food to protect a budget line is the wrong default |
| q7 | Is one pinned category per user enough? | Yes for phase 1. A user splitting groceries across two categories is a phase-2 question |

## Success criteria

- [ ] A user with declared habits and a stocked pantry receives a list containing only the difference
- [ ] A gifted pantry item removes its product from the list and changes no `Transaction` and no income figure
- [ ] The grocery ceiling is read by `category_id`, and renaming the category changes nothing
- [ ] With no link, the response reports the ceiling as **absent** — asserted by test, because the silent zero is the bug being prevented
- [ ] Deleting the pinned category succeeds and returns the capability to the no-link state
- [ ] A quantity in a unit the product does not declare is rejected with a validation error
- [ ] `php artisan test`, `phpstan analyse` and `pint --test` pass; `BoundariesTest` and `CrossUserOwnershipTest` pass unchanged
- [ ] `docs/domain/data-dictionary.md` is regenerated, and Shopping appears in both `openspec/config.yaml` and `docs/domain/context-map.md`
