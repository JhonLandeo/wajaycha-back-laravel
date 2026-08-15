# wajaycha-back-laravel

Laravel 11 API. **Sole owner of the Wajaycha financial domain.**

## Read the shared context first

This repository is part of the Wajaycha workspace. Domain language, subdomain
boundaries, architecture diagrams, and accepted decisions live one level up:

- `../CLAUDE.md` — workspace entry point
- `../docs/domain/ubiquitous-language.md` — **read before touching `Detail`, `Transaction`, `Category`, or `ParetoClassification`**
- `../docs/domain/context-map.md` — subdomain boundaries
- `../docs/architecture/technical-debt.md` — verified outstanding issues
- `../docs/decisions/` — accepted decisions

## Code

**Thin controllers.** A controller receives a request, calls one thing, returns a
response. Business logic lives in `app/Actions/` (use cases) or `app/Services/`;
data access goes through `app/Repositories/`, contracts in
`app/Repositories/Contracts/`.

**`declare(strict_types=1);` in every new PHP file.** Adoption is partial — 70 of
131 files in `app/` carry it today. New files are not the place to widen the gap.

**DTOs for anything structured** crossing a boundary. `app/DTOs/` is organised by
subdomain.

**Queue the heavy work.** Image parsing, PDF handling and anything calling Gemini
goes to Redis via Horizon, never inline in a request.

**No new dependency** for something Laravel or plain PHP resolves in under fifty
lines.

**Nothing commented-out**, no `dd()`, no `dump()` in a final change.

## Database (PostgreSQL)

**Naming.** Tables `snake_case` plural; columns `snake_case` singular; primary key
always `id`; foreign keys `[singular_table]_id`. Booleans read as predicates:
`is_`, `has_`, `can_`.

**Index and constraint prefixes:** `idx_[table]_[column]`, `unq_[table]_[column]`,
`fk_[table]_[column]`. The first two are in real use (12 and 6 occurrences).
**No migration uses `fk_`** — foreign keys currently take Laravel's generated
names. Either follow the convention on new work or drop it from this file; it
should not sit here describing something that is not happening.

**Money is `numeric(15,2)` or `decimal`. Never `float`, `real` or `double
precision`.** No migration violates this today; keep it that way — floating point
rounding in a ledger is the failure this product cannot detect.

**Timestamps are `timestamptz`.**

**Text**: prefer `text` unless a business rule caps the length. `jsonb` for
unstructured payloads.

**Comment tables and principal columns** in the migration (`$table->comment(...)`).

**Query rules.** No `SELECT *` — name the columns. Keep `WHERE` clauses sargable:
`created_at >= '2026-04-19' AND created_at < '2026-04-20'`, never
`DATE(created_at) = '2026-04-19'`, which discards the index. Eager-load with
`with()` whenever a collection is iterated. Reason about `EXPLAIN ANALYZE` for any
non-trivial query.

**Triggers do plumbing, not business logic** — `updated_at`, lineage, audit rows.

**Soft deletes only where financial history or referential integrity demands it.**

### Business rules currently living in SQL

`database/migrations/` defines PostgreSQL functions that carry Financial Analysis
logic — `fn_get_top_five_data`, `create_function_summary_by_day`,
`exclude_income_from_pareto` and others, invoked from controllers via
`DB::select()`.

**Do not add to them.** [ADR-0009](../docs/decisions/0009-coach-narrates-does-not-advise.md)
records why: a stored procedure can return 82% but cannot return *why* 82%
matters, and the Financial Coaching subdomain needs the reason, not the number.
Moving these rules into PHP is an open blocker, not a preference.

## Warnings

- `PLAN_REFACTORIZACION.md` is a **2026-04-19 historical audit**, not current
  state. Several of its findings are already fixed. See `../docs/decisions/0003-supersede-refactoring-plans.md`.
- `../wajaycha-nest/` is abandoned. Do not read it as a reference. See `../docs/decisions/0001-discard-nest-microservice.md`.
- `config/database.php` defaults to `sqlite`. The system requires PostgreSQL
  with pgvector and the SQL functions in `database/migrations/`.
- `.env.example` does not document any `WHATSAPP_*` key, and its
  `SENTRY_ENVIRONMENT` line is glued to `TELEGRAM_BOT_USERNAME=`, so that
  variable is never defined. Unfixed.
