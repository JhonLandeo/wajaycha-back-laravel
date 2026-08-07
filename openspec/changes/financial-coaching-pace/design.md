# Design — Pace-aware spending coach

- **Change:** `financial-coaching-pace`
- **Date:** 2026-08-06
- **Owning subdomain:** Financial Coaching (first code) — reads Financial Analysis, reaches through the Capture channel port
- **Inputs:** [`proposal.md`](proposal.md), [`exploration.md`](exploration.md), [ADR-0007](../../../../docs/decisions/0007-telegram-primary-conversational-channel.md), [ADR-0005](../../../../docs/decisions/0005-opportunistic-hexagonal-refactor.md)
- **Requirements:** owned by `sdd-spec`, running in parallel. This document is the HOW.

> Size note: this design exceeds the default 800-word artifact budget deliberately. Column-level
> data dictionary, two sequence diagrams and per-fork rejected alternatives were explicitly
> requested and are load-bearing for `sdd-tasks`.

---

## 1. Technical approach

One owning service, `App\Services\Coaching\FinancialCoachingService`, exposes exactly **one**
public method. Both entry points — the scheduled sweep and the capture-time check — call that
method and differ only in the scope object they pass. "One voice" is therefore structural, not a
convention that reviewers must police.

```
Command  ──┐                                    ┌──> CategoryRepository ──> get_monthly_category_budget_report()
           ├──> FinancialCoachingService::speak ┤
Job      ──┘         (the only decider)         ├──> PaceEvaluator          (pure PHP, no DB)
                                                ├──> TransactionRepository  (set-based cause + largest expense)
                                                ├──> SpokenObservationLedger (claim-by-INSERT)
                                                ├──> CoachingMessageComposer (Spanish copy)
                                                └──> ChannelIdentityResolver ──> TelegramChannel::reply()
```

Per `.agents/rules/01-laravel-core.md` and `openspec/config.yaml`: the Command and the Job
**orchestrate**; they hold no threshold, no band, no string. `PaceEvaluator` decides nothing about
delivery; `FinancialCoachingService` decides nothing about arithmetic. Neither the capture port
(`CaptureChannel`) nor either adapter changes — `reply()` is already context-free.

---

## 2. Owning service and namespaces (fork 5)

| Class | Namespace | Responsibility | DB? |
|---|---|---|---|
| `FinancialCoachingService` | `App\Services\Coaching` | **The owning service.** `speak(User $user, CoachingScope $scope): bool`. Sequences: snapshot → evaluate → ladder → cause → claim → compose → send → confirm. | via collaborators |
| `PaceEvaluator` | `App\Services\Coaching` | Pure arithmetic: expected, projected close, band, lumpiness, ordering. No facades, no Eloquent, no `now()`. | **no** |
| `SpokenObservationLedger` | `App\Services\Coaching` | The spoken-observation memory. `highestBandFor()`, `claim()`, `confirmSent()`. | yes |
| `CoachingMessageComposer` | `App\Services\Coaching` | Observations → Spanish text. No decisions about *whether* to speak. | no |
| `CategoryMonthSnapshot`, `PaceObservation`, `MonthCursor`, `PaceThresholds`, `CoachingScope` | `App\DTOs\Coaching` | Immutable readonly DTOs (core rule: DTOs for complex parameters). | no |
| `RunCoachingSweep` | `App\Console\Commands` | `app:run-coaching-sweep`. Iterates reachable users, calls `speak()`, prints counters. | no |
| `ProcessTelegramCapture` (modified) | `App\Jobs` | After the ✅ confirmation, one guarded `speak()` call. | no |
| `ChannelIdentityResolver` (modified) | `App\Services\Capture` | Gains the reverse path. Identity stays owned by Capture. | yes |
| `config/coaching.php` | — | Thresholds, caps, channel preference, kill switch. Env-backed. | no |

Modules stay logical. No folder restructuring (ADR-0005: `app/Services/Coaching/` is new code, not a
move of existing code).

---

## 3. Architecture decisions

### D1 — The pace arithmetic lives in PHP (fork 1: **confirmed**, with the boundary made precise)

| Option | Verdict | Reason |
|---|---|---|
| **A — PHP in `PaceEvaluator`** | **Chosen** | The thresholds *are* product judgement and will be retuned by the owner against real messages. In PHP that is a config edit plus a unit test; in SQL it is a migration. Technical-debt item 10 already records nine business-rule SQL functions that "cannot be tested without a live database"; a brand-new subdomain must not open with number ten. There is no performance argument to trade: ~20 leaf categories per user, ~100 users. |
| B — `get_category_pace_report()` SQL function | Rejected | Matches the dominant pattern and deepens the exact debt the workspace has already diagnosed. |
| C — Hybrid: SQL returns bands, PHP formats | Rejected | Worst of both. The threshold still lives in a migration, and the split makes neither side independently testable. |

**Bounded exception — what stays set-based.** Aggregation does not move into PHP. It stays in the
database and is reached **only through repository methods**:

- month-to-date spend and budget per category → the existing `get_monthly_category_budget_report`,
  called through `CategoryRepository` (item 10's stated direction: wrap, do not bypass);
- merchant cause breakdown and largest single expense → **new query-builder aggregations** on
  `TransactionRepository`.

**No new PostgreSQL object is created by this change.** The cause queries are Eloquent/query-builder
aggregations, not `CREATE FUNCTION`. This honours the bounded exception *and* item 10 simultaneously,
which a new SQL function would not.

**Rejected — reuse `get_summary_transaction_by_day()`** as a pace precedent: its
`v_amount_total NUMERIC := 2000;` is a hardcoded whole-account budget belonging to nobody
(exploration §3). It is deleted, not extended.

### D2 — Read `spent` from the same function the SPA reads

| Option | Verdict | Reason |
|---|---|---|
| **Reuse `get_monthly_category_budget_report`** | **Chosen** | The coach's credibility is the product. If the message says `S/ 340 de 400` and the SPA says `S/ 355`, both numbers die (QA-3 integrity > QA-2 accuracy). One source of `spent` is worth more than a cleaner query. |
| A private coaching aggregation | Rejected | Two definitions of "spent this month" is exactly the contradiction fork 2 deletes the daily summary to avoid. |

Consequences that must be handled in the repository method, not hidden:

1. The function is paginated. `CategoryRepository::expenseBudgetSnapshotsForMonth()` calls it with
   `p_page = 1`, `p_per_page = config('coaching.max_categories')` (500) and **throws** when the
   returned `total_records` exceeds the ceiling, rather than silently coaching a truncated list.
   Precedent exists: `FinancialReportService::getBudgetDeviation()` already calls it with `(1, 100)`.
2. The function returns no `type` column. The repository intersects the rows with
   `Category::where('user_id', …)->where('type', 'expense')->pluck('id')` — one extra indexed query.
   Only `type = 'expense'` reaches the evaluator. `categories.type` is an enum of `income`, `expense` and `transfer`; there is no `savings` value, so the filter is positive rather than a list of exclusions.
3. The function's `spent` is **expenses minus income within the category**. That is correct budget
   behaviour (a refund restores budget) and is kept. Categories with `spent <= 0` are skipped.
4. The function returns leaf categories only (`parent_id IS NOT NULL OR NOT EXISTS children`), so
   there is no parent/child double counting.

### D3 — Day-of-month and timezone (fork 3)

**Reference timezone: `America/Lima`, declared once and applied at both ends.**

| Where | What is bound |
|---|---|
| `config/app.php` | Already `America/Lima`. Unchanged. It is the single declaration. |
| `config/database.php` → `pgsql` | **Add `'timezone' => env('DB_TIMEZONE', 'America/Lima')`.** Laravel's `PostgresConnector` issues `SET TIME ZONE` from this key. |
| `MonthCursor` | Built from `CarbonImmutable::now(config('app.timezone'))`. Carries `day`, `daysInMonth`, `periodMonth` (first day of month), `startsAt`, `endsAt` (half-open instants). |
| `TransactionRepository` cause queries | `date_operation >= :startsAt AND date_operation < :endsAt` — sargable per rule 02 §3, and timezone-explicit rather than relying on `EXTRACT`. |

**Why the connection timezone must be pinned.** `date_operation` is `timestamptz`, and
`get_monthly_category_budget_report` buckets with `EXTRACT(YEAR/MONTH FROM date_operation)`, which
PostgreSQL evaluates **in the session TimeZone**. Today no `timezone` key exists on the `pgsql`
connection, so no `SET TIME ZONE` is issued and the session inherits the server default. `d` would
then come from PHP's Lima calendar while `D`-scoped `spent` comes from the server's calendar. Pace
computed across two calendars is not wrong at the margin — it is undefined. Pinning removes the
ambiguity for the coach and for every existing report at the same time.

**Blast radius, stated plainly.** This one line changes month bucketing for *every* existing SQL
report at month edges. If the server is already `America/Lima` the change is a no-op. It is
opportunistic per ADR-0005 (this change is the first to depend on month bucketing being defined).
**Verification is mandatory before merge:** compare `get_monthly_category_budget_report` totals for
the current and previous month, before and after, on a production copy. Rollback is deleting the line.

**Month boundaries.** A month is the half-open Lima interval `[first day 00:00, next first day 00:00)`.
On day 1 the previous month is closed and never re-coached: the ledger key includes `period_month`,
so August observations can never be re-spoken in September, and September starts with an empty
ladder. No projection runs before `config('coaching.min_day_for_projection')` = 5.

**What breaks if a user is in a different zone than the server.** There is no `users.timezone`
column and no per-user cursor — verified: `User::$fillable` carries no timezone. Every user is
coached on Lima's calendar. A user in Madrid who spends at 02:00 local on the 1st is counted in
Lima's previous month, and *"el día 12"* means Lima's day 12, which may be their 13th. This is
accepted while the product is single-market Peruvian. The fix — a `users.timezone` column feeding
`MonthCursor` and the range predicates — is a clean extension because the cursor is already the only
place the calendar is decided. Out of scope; recorded here as the boundary condition.

### D4 — Reverse channel lookup (fork 4)

The reverse path stays in `App\Services\Capture\ChannelIdentityResolver`, because Capture owns
`channel_identities`. Coaching consumes it and never queries the table directly.

```
public function resolve(string $channel, string $externalId): ?User          // existing, untouched
public function preferredIdentityFor(int $userId, array $channels): ?ChannelIdentity   // new
public function userIdsReachableOn(array $channels): LazyCollection          // new, chunked
```

- `$channels` is an **ordered preference list**, passed by the caller, never defaulted inside the
  resolver. The coach passes `config('coaching.channels')`, which is `['telegram']` — not
  `['telegram', 'whatsapp']`. Telegram is chosen because it is the only channel that may send
  unprompted without Meta template approval (ADR-0007). Ordering the array is how a future WhatsApp
  template channel is admitted: a config change, not an edit to the resolver.
- `channel_identities` is unique on `(channel, external_id)` and only **indexed** on `user_id`, so a
  user may hold both identities. Preference is applied in PHP over the user's rows in the declared
  order, which makes the choice explicit and testable rather than dependent on row order.
- `userIdsReachableOn()` returns `DISTINCT user_id` restricted to the given channels, lazily. At ~100
  users this is trivial; it is chunked anyway so the sweep never loads the table.

**A WhatsApp-only user is never coached — accepted and observable.** They simply do not appear in
`userIdsReachableOn(['telegram'])`. To keep that from being invisible silence, `RunCoachingSweep`
prints and logs a summary line every run:

```
Coaching: 118 usuarios, 96 alcanzables por telegram, 22 sin canal de coaching, 7 mensajes enviados, 89 silencios.
```

`22 sin canal de coaching` is the observable. Rejected alternative — falling back to WhatsApp when
Telegram is absent: it turns a designed silence into a Meta template violation risk (QA-4, rank 1).

### D5 — Cause and largest-transaction share (fork 6)

`get_transactions_by_detail()` is **not used**. Two reasons, and the second is the one usually missed:

1. Its `HAVING COUNT(t.id) > 1` drops single-transaction merchants — precisely the lumpy case that
   most needs naming.
2. It reads the raw `transactions` table while `spent` comes from `v_unified_transactions`. Its sign
   convention is also inverted (`income − expense`). Deriving a percentage of `spent` from it would
   produce shares that do not reconcile with the number in the same sentence.

Two new repository methods, both reading `v_unified_transactions` — **the same source as `spent`**,
so every percentage in a message reconciles with the amount beside it:

| Method | Shape | Notes |
|---|---|---|
| `TransactionRepository::topMerchantsForCategoryMonth(int $userId, int $categoryId, CarbonImmutable $startsAt, CarbonImmutable $endsAt, int $limit)` | `detail_id, detail_name, SUM(amount) AS total, COUNT(*) AS frequency` grouped by detail, ordered `total DESC`, limited | `type_transaction = 'expense'`. **No `HAVING`.** Explicit columns, no `SELECT *` (rule 02 §3). |
| `TransactionRepository::largestExpenseForCategoryMonth(int $userId, int $categoryId, CarbonImmutable $startsAt, CarbonImmutable $endsAt)` | `detail_name, amount, date_operation` of the single max-amount expense row | Feeds the lumpiness test and the lumpy message. |

Both are called **only for observations that survived the evaluator and the band ladder** — at most
3 categories per user per run, never once per category.

**Refund guard.** Because `spent` nets income while the cause queries do not, the top merchant's
total can exceed `spent`. When it does, the composer **omits the percentage** and names the merchant
without a share. It never renders a share above 100%.

### D6 — Spoken-observation memory: claim-by-INSERT (fork 2)

The write is **constraint-enforced, never read-then-write**, reusing the pattern already established
three times (`ChannelUpdateDeduplicator::claim`, `ChannelIdentityLinker::linkWhatsApp`,
`ChannelLinkTokenRedeemer::redeem`). No fourth variant is invented:

```php
try {
    // Savepoint propio: en PostgreSQL una violacion de constraint aborta la transaccion
    // que la contiene, asi que sin esto atrapar la excepcion dejaria inservible
    // cualquier transaccion que nos envuelva.
    DB::transaction(fn () => CoachingObservation::create([...]));
    return true;
} catch (UniqueConstraintViolationException) {
    return false;   // ya se dijo; el silencio es correcto
}
```

**Why the unique key can arbitrate at all.** The bands form a strict severity ladder:

| Band | Severity | Meaning |
|---|---|---|
| `projected_over` | 1 | Projected close exceeds budget by ≥ `overrun_margin`, spend not yet over |
| `over_budget` | 2 | Month-to-date spend already exceeds budget |
| `blind` | — | Not comparable. Per user, per month, once. |

The unique index on `(user_id, period_month, subject_key, band)` alone guarantees *"never the same
band twice in a month"*. The only thing PHP still decides is *"never speak downward"* — if
`over_budget` was already said and the category later falls back to `projected_over`, the ladder
check suppresses it. That check reads `highestBandFor()` and is **not** correctness-critical: under
a race two processes both read `projected_over`, both compute `over_budget`, both attempt the insert,
one wins and one is silenced by the constraint. The worst observable outcome of any race is one
*extra* message that was genuinely warranted, never a duplicate of the same band. There is no
per-user job serialisation and none is added.

**Lumpiness is not a band.** It is a presentation flag on the same observation. Because lumpiness
suppresses projection, a lumpy category can only reach `over_budget` — which is exactly the settled
behaviour ("a category blown by one transaction reports level and names that transaction; it does
not project"). This keeps the ladder two-valued and the constraint sufficient.

**`subject_key` exists because PostgreSQL considers NULLs distinct in a unique index.** A nullable
`category_id` in the key would let the blindness report be spoken every single day. `subject_key` is
`'category:{id}'` or the literal `'blindness'`, `NOT NULL`. `category_id` is kept alongside it as a
real FK for cascade delete and for querying, but is deliberately *not* part of the key.

Rejected alternatives:

| Option | Rejected because |
|---|---|
| Cache / Redis key | Not durable, not queryable, and invisible to the operator when the coach goes quiet. |
| Two partial unique indexes (one `WHERE category_id IS NOT NULL`, one `WHERE subject_type='blindness'`) | Works, but requires raw `CREATE UNIQUE INDEX … WHERE` outside Blueprint and gives two arbiters instead of one. `subject_key` gets the same guarantee with one index and no NULL semantics. |
| `firstOrCreate` / `updateOrCreate` | It is a read-then-write with a lost-update window, and `firstOrCreate`'s non-update behaviour is already a live defect elsewhere in this codebase. |
| Advisory lock per user | Serialises what the constraint already arbitrates, and leaks a lock across two entry points with different lifetimes. |
| Storing "last spoken at" on `categories` | Mutating a domain table for notification bookkeeping; no history, no per-band granularity, and it collides with SPA writes. |

### D7 — Claim before send (fork 7)

**The observation is claimed *before* `reply()` is called.**

The alternative destroys the mechanism, not just the ordering: if the send happens first, a sweep
and a capture-time check can both send before either records, which is precisely the double message
the constraint exists to prevent. The insert must precede the send for the unique index to be able
to arbitrate at all. Cost accepted: a send that fails after a successful claim silences that band for
that category for the rest of the month.

That cost is bounded and instrumented rather than hidden:

- `spoken_at` is written at claim time.
- `sent_at` is written after `reply()` returns without throwing. **This is not proof of
  delivery** — `TelegramChannel::reply()` logs and swallows a non-2xx response and returns `void`.
  It proves only that the process survived the call. It exists to distinguish *"the worker died
  between claim and send"* from *"the send returned"*, which is the difference an operator needs.
- Rows with `spoken_at IS NOT NULL AND sent_at IS NULL` are the operator's queryable signal.
- **No automatic retry.** Retrying would require reopening the band, which reintroduces duplicates.
  Recovery is manual (delete the row), matching the existing no-retry contract of `reply()` on both
  channels.
- Worst case per incident: one band, one category, one month, one user goes unspoken. The next
  worse band still speaks.

### D8 — `actual_percentage` is never narrated (non-negotiable)

`actual_percentage` is `SUM(budget of categories in bucket) / SUM(budget of all non-income
categories)` — **budget-allocation weight**. Nothing in this system computes a bucket's share of
actual spend. The coach never reads `get_pareto_monthly_report` and never emits a Pareto bucket
share in any form. The only percentages this change is permitted to render are:

1. `spent / monthly_budget` for one category, and
2. a merchant's or a single transaction's share of that category's month-to-date spend, computed by
   D5 from the same rows as `spent`.

If declared-versus-real is ever needed, the genuine pair is `percentage` against `percentage_spent`.
`sdd-spec` should carry this as a MUST NOT with a named test.

### D9 — Miscategorisation is a design constraint, not a nicety

`CategorizationService` has no unit tests, and a miscategorised transaction becomes a spoken
accusation (QA-2, rank 4). The mitigation is the message shape and it is therefore **binding**:

> **Invariant:** every observation with `spent > 0` names the category **and** at least one merchant.

Because `topMerchantsForCategoryMonth()` carries no `HAVING`, an empty result is only possible when
the category has no expense rows at all — impossible when `spent > 0`. A wrong reading is therefore
always attributable and correctable by the user, instead of being an unfalsifiable number.

### D10 — Plain text only

`CoachingMessageComposer` output goes to `TelegramChannel::reply()`, which posts `sendMessage`
**without `parse_mode`**. That must stay true. `detail_name` is user-controlled content derived from
a Gemini parse of the user's own message; under `Markdown` or `HTML` parse mode an unbalanced `*`
or `<` in a merchant name would either break the send or inject formatting. Plain text is the safe
default and it is already what the adapter does — another reason the port stays untouched.

---

## 4. Data model

### New table: `coaching_observations`

Purely additive. No existing column, row, migration, SQL function or view is modified.
Column comments are Spanish to match the adjacent `channel_identities` and
`processed_channel_updates` migrations; the artifact language rule governs documents, and the
in-database dictionary must stay internally consistent.

| Column | Type | Null | Comment (`$table->comment(...)`) |
|---|---|---|---|
| `id` | `bigIncrements` | no | — |
| `user_id` | `foreignId` → `users`, `cascadeOnDelete` | no | `Usuario al que se le hablo.` |
| `period_month` | `date` | no | `Primer dia del mes coacheado en la zona horaria de referencia (America/Lima). Es una etiqueta de calendario, no un instante: guardarlo como timestamptz reintroduciria justo la ambiguedad que esta columna existe para eliminar.` |
| `subject_key` | `varchar(40)` | no | `Sujeto de la observacion: "category:{id}" o el literal "blindness". Nunca NULL: PostgreSQL considera distintos los NULL en un indice unico, y un category_id nulo dejaria repetir el aviso de ceguera todos los dias.` |
| `category_id` | `foreignId` → `categories`, `nullOnDelete` | yes | `Categoria observada. NULL cuando el sujeto es la ceguera del coach. Existe para integridad y consultas; la clave unica usa subject_key, no esta columna.` |
| `band` | `varchar(20)` | no | `Banda de severidad ya hablada: projected_over, over_budget o blind. Solo se vuelve a hablar al cruzar a una banda peor; el indice unico impide repetir la misma banda en el mes.` |
| `is_lumpy` | `boolean`, default `false` | no | `True cuando una sola transaccion domina el gasto del mes en la categoria y por eso se reporto nivel en lugar de proyeccion.` |
| `budget_amount` | `numeric(15,2)` | no | `Presupuesto mensual de la categoria al momento de hablar.` |
| `spent_amount` | `numeric(15,2)` | no | `Gasto acumulado del mes en la categoria al momento de hablar.` |
| `projected_amount` | `numeric(15,2)` | yes | `Cierre proyectado que se comunico. NULL cuando no se proyecto: antes del dia minimo, o cuando el gasto es concentrado en una sola transaccion.` |
| `day_of_month` | `smallInteger` | no | `Dia del mes, en la zona horaria de referencia, sobre el que se hablo. Se guarda para poder auditar el mensaje sin recalcular el calendario.` |
| `entry_point` | `varchar(12)` | no | `Que voz hablo: sweep (barrido programado) o capture (chequeo al registrar). Ambas comparten reglas; esto solo permite observarlas por separado.` |
| `spoken_at` | `timestampTz` | no | `Momento en que se reclamo la observacion. El reclamo ocurre ANTES del envio: el indice unico solo puede arbitrar entre el barrido y la captura si el insert precede al mensaje.` |
| `sent_at` | `timestampTz` | yes | `Momento en que reply() retorno sin excepcion. NO es acuse de entrega: el adaptador registra y traga los fallos HTTP. Una fila con spoken_at y sent_at NULL indica que el proceso murio entre el reclamo y el envio.` |
| `created_at` / `updated_at` | `timestampsTz` | — | — |

Table comment:
`Observaciones de coaching ya dichas. Existe para que el barrido programado y el chequeo al capturar nunca repitan el mismo mensaje, y para que "no decir nada" sea un resultado verificable.`

Indexes:

| Name | Columns | Why |
|---|---|---|
| `unq_coaching_observations_user_period_subject_band` | `(user_id, period_month, subject_key, band)` | **The arbiter.** Every re-speak decision resolves here, not in application code. |
| `idx_coaching_observations_spoken_at` | `(spoken_at)` | Pruning old periods and operator queries. |

No separate `(user_id, period_month)` index: the unique index's leading columns already serve the
band-ladder lookup. Adding one would be a redundant write cost on every claim.

`numeric(15,2)` for all money per rule 02 §2. `timestamptz` for every instant. `date` for
`period_month` is the one deliberate exception, justified in its own comment above.

Model: `App\Models\CoachingObservation` — `$fillable`, `period_month` cast to `immutable_date`,
`spoken_at`/`sent_at` cast to `immutable_datetime`, `is_lumpy` to `boolean`.

### No other schema change

`get_monthly_category_budget_report`, `get_pareto_monthly_report`, `get_transactions_by_detail`,
`v_unified_transactions`, `channel_identities`, `categories` and `transactions` are all **read only**.

---

## 5. Decision flow

### 5.1 The evaluator (shared by both entry points, pure PHP)

For each leaf **expense** category with `monthly_budget > 0` and `spent > 0`, at day `d` of a month
of `D` days:

```
expected  = budget × d / D
projected = spent  × D / d
```

| Order | Test | Outcome |
|---|---|---|
| 1 | `spent > budget` | `over_budget`. Lumpiness decides the wording, not the band. |
| 2 | `d < min_day_for_projection` (5) | **No observation.** Before day 5 only actual overspend speaks. |
| 3 | largest single expense `>= lumpy_share (0.50) × spent` | **No observation** and no projection. A lumpy category that is not yet over has nothing honest to say. |
| 4 | `projected >= budget × (1 + overrun_margin)` (1.10) | `projected_over` |
| 5 | otherwise | No observation. |

Blindness (sweep scope only): leaf **expense** categories with `monthly_budget = 0` **and**
`spent > 0`. One observation for the whole set, `subject_key = 'blindness'`, `band = 'blind'`.

Ordering by severity: `over_budget` first, descending by `spent / budget`; then `projected_over`,
descending by `projected / budget`; blindness last. Truncated to
`config('coaching.max_observations_per_message')` = 3.

`PaceEvaluator` receives `CategoryMonthSnapshot[]`, a `MonthCursor` and `PaceThresholds` and returns
`PaceObservation[]`. It touches no clock, no database and no config directly — that is what makes
fork 1's promise ("unit-tested with no database") mechanically true rather than aspirational.

### 5.2 Scheduled sweep

```mermaid
sequenceDiagram
    autonumber
    participant Sch as Scheduler
    participant Cmd as RunCoachingSweep
    participant Res as ChannelIdentityResolver
    participant Coach as FinancialCoachingService
    participant CatR as CategoryRepository
    participant Ev as PaceEvaluator
    participant Led as SpokenObservationLedger
    participant TxR as TransactionRepository
    participant Comp as CoachingMessageComposer
    participant Tg as TelegramChannel

    Sch->>Cmd: app:run-coaching-sweep (diario, 20:00 America/Lima)
    Cmd->>Res: userIdsReachableOn(['telegram'])
    Res-->>Cmd: LazyCollection<userId> (los solo-WhatsApp no aparecen)

    loop por cada usuario alcanzable
        Cmd->>Coach: speak(user, CoachingScope::sweep())
        Coach->>CatR: expenseBudgetSnapshotsForMonth(user, month, year)
        CatR-->>Coach: CategoryMonthSnapshot[] (gasto + presupuesto, hojas, solo egreso)
        Coach->>Ev: evaluate(snapshots, cursor, thresholds)
        Ev-->>Coach: PaceObservation[] ordenadas por severidad

        Coach->>Led: highestBandFor(user, periodMonth)
        Led-->>Coach: banda maxima por sujeto
        Note over Coach: descarta lo que no cruza a una banda peor

        alt no queda nada
            Coach-->>Cmd: false (silencio; no se envia nada)
        else queda al menos una
            loop por observacion (max 3)
                Coach->>TxR: topMerchantsForCategoryMonth(...) / largestExpenseForCategoryMonth(...)
                TxR-->>Coach: comercio dominante / transaccion mayor
                Coach->>Led: claim(user, period, subject, band)  %% INSERT
                alt UniqueConstraintViolationException
                    Led-->>Coach: false (otra voz ya la dijo)
                else
                    Led-->>Coach: true (reclamada, spoken_at)
                end
            end
            Coach->>Comp: compose(observaciones reclamadas)
            Comp-->>Coach: texto en español, sin consejo
            Coach->>Res: preferredIdentityFor(user, ['telegram'])
            Res-->>Coach: ChannelIdentity
            Coach->>Tg: reply(chatId, texto)
            Note right of Tg: sin parse_mode; los fallos se registran y se tragan
            Coach->>Led: confirmSent(ids)  %% sent_at
            Coach-->>Cmd: true
        end
    end
    Cmd-->>Sch: "N usuarios, M alcanzables, K sin canal, J enviados, S silencios"
```

The claim happens **inside** the per-observation loop and before composition, so a band another
voice already claimed is dropped from the message rather than sent and then deduplicated.

### 5.3 Capture-time check

```mermaid
sequenceDiagram
    autonumber
    participant Job as ProcessTelegramCapture
    participant Act as RegisterCapturedTransactionAction
    participant Tg as TelegramChannel
    participant Coach as FinancialCoachingService
    participant CatR as CategoryRepository
    participant Ev as PaceEvaluator
    participant Led as SpokenObservationLedger
    participant TxR as TransactionRepository

    Job->>Act: execute(user, parsedReceipt)
    Act-->>Job: Transaction (category_id, amount, type_transaction)
    Job->>Tg: reply("✅ Registrado: S/ …")

    alt category_id NULL, ingreso, o coaching deshabilitado
        Note over Job: no se coachea. Una transaccion sin categoria no puede acusar a nadie.
    else gasto categorizado
        Job->>Coach: speak(user, CoachingScope::forCategory(categoryId, amount))
        Coach->>CatR: expenseBudgetSnapshotsForMonth(user, month, year)
        CatR-->>Coach: snapshots
        Note over Coach: se queda solo con la categoria del movimiento
        Coach->>Ev: evaluate([snapshot], cursor, thresholds)
        Coach->>Ev: evaluate([snapshot con spent - amount], cursor, thresholds)
        Note over Coach: contrafactual: ¿esta transaccion es la que cruzo la linea?
        alt la banda no empeoro por esta transaccion
            Coach-->>Job: false (silencio)
        else
            Coach->>Led: highestBandFor(user, periodMonth, subject)
            Coach->>TxR: topMerchants / largestExpense
            Coach->>Led: claim(...)  %% INSERT; el barrido pudo ganar
            alt reclamada
                Coach->>Tg: reply(chatId, texto)
                Coach->>Led: confirmSent(id)
                Coach-->>Job: true
            else ya reclamada
                Coach-->>Job: false (el barrido ya lo dijo hoy)
            end
        end
    end
```

Three properties fall out of this shape:

- **"Only if that transaction crossed the line"** is a counterfactual over the same evaluator, not a
  second rule. Nothing about the capture path can drift from the sweep's judgement.
- The coaching call is **inline, after the confirmation**, inside `try/catch (Throwable)`. The
  transaction is already persisted; letting a coaching failure fail the job would retry it and
  duplicate the transaction — the same reasoning already documented on `reply()`. The catch logs and
  returns.
- Blindness is never emitted from the capture path (single-category scope). It is a sweep concern.

---

## 6. Message composition

Spanish, matching the existing bot copy. Amounts as `S/ ` + `number_format($x, 2)`. No advice, no
suggestion, no next step, no question, no buttons.

| Band | Shape |
|---|---|
| `projected_over` | `{Categoría}: S/ {spent} de S/ {budget} el día {d}, a este ritmo cerrás en S/ {projected}. El {share}% son {frequency} de {merchant}.` |
| `over_budget`, linear | `{Categoría}: S/ {spent} de S/ {budget} el día {d}, ya pasaste el presupuesto. El {share}% son {frequency} de {merchant}.` |
| `over_budget`, lumpy | `{Categoría}: S/ {spent} de S/ {budget} el día {d}, ya pasaste el presupuesto. El {share}% es una sola compra: {merchant}, S/ {amount} el {día}.` — **no projection** |
| `blind` | `No puedo mirar {n} categoría(s) sin presupuesto: {nombres}. Este mes registraste S/ {total} ahí.` |

Composition rules:

1. Multiple observations join with `\n\n`, in severity order, capped at 3.
2. The cause clause is **omitted entirely** when the top merchant's total exceeds `spent` (refund
   guard) — the merchant is still named, without a share. A share above 100% is never rendered.
3. The frequency word is singular/plural correct (`1 compra` / `4 compras`) and uses the merchant's
   `detail_name` verbatim — `Detail` is the normalised counterparty entity, exactly what the user
   recognises.
4. Never render a Pareto bucket share (D8). Never render `actual_percentage`.
5. No `parse_mode` (D10).
6. There is **no "todo bien" message.** Silence is a first-class outcome and `speak()` returns
   `false`.

---

## 7. Failure and rollback behaviour

| Failure | Behaviour |
|---|---|
| Telegram `sendMessage` non-2xx | `reply()` logs and swallows (existing contract). `sent_at` is still set — it only proves the call returned. The band stays claimed. No retry. |
| Worker dies between claim and send | Row with `spoken_at` set, `sent_at` NULL. Operator-queryable. Manual recovery only. |
| Sweep and capture race | Constraint arbitrates. Loser is silent. Never a duplicate band. |
| `expenseBudgetSnapshotsForMonth` exceeds the 500-category ceiling | **Throws.** Coaching a truncated category list would produce confidently wrong silence. |
| Cause query returns nothing while `spent > 0` | Structurally impossible without `HAVING`. If it ever happens, the observation is dropped rather than sent causeless (D9 invariant). |
| Coaching throws inside `ProcessTelegramCapture` | Caught, logged, swallowed. The capture and its ✅ confirmation already succeeded (QA-1). |
| A user has no Telegram identity | Not in the sweep list. Counted in the summary line. No error, no retry. |

**Rollback, in increasing cost:**

1. **No deploy** — `COACHING_ENABLED=false`, or `COACHING_MAX_OBSERVATIONS=0`. Both entry points go
   silent; capture behaviour returns to exactly today's.
2. **Schedule only** — remove the sweep entry from `routes/console.php`.
3. **Revert commits** — every slice reverts independently.
4. **Schema** — `Schema::dropIfExists('coaching_observations')`. Purely additive; nothing else to undo.
5. **Timezone pin** — delete the `'timezone'` line from `config/database.php`.

---

## 8. Retiring the two summaries — sequencing (overlap, never gap)

The requirement is that the owner is never left without any message in between. The answer is a
deliberate **overlap**, not a swap.

| Step | Lands in | State after |
|---|---|---|
| 1 | Slice 4 | `app:run-coaching-sweep` scheduled `dailyAt('20:00')`. `SendSummaryTransactionsByDay` **still scheduled at `20:08`**. For at least one deploy the owner receives both. Accepted: eight minutes of two voices beats one day of none, and it is the only window in which the coach can be compared against what it replaces. |
| 2 | Slice 6, **never before slice 4** | Delete `SendSummaryTransactionsByDay` and its schedule entry. Note it also carried a daily email (`NotificationSummaryByDay`); that email goes with it, by the same argument — its numbers come from the same hardcoded `v_amount_total := 2000`. |
| 3 | Slice 6 | `SendSummaryTransactionByMonth`: delete the WhatsApp block entirely. `$totalReal = $budgetDeviation->sum('real')` exists **only** inside that block, so removing the push removes the defect from the command. The monthly email and its `monthlyOn(1, '08:00')` schedule are kept. |

**Additional finding — the monthly email is more broken than the proposal recorded.**
`resources/views/emails/summary_month.blade.php` reads `$item->category`, `$item->real`,
`$item->variance` and `$item->status`, while `FinancialReportService::getBudgetDeviation()` returns
`id, name, budgeted, spent, available_budget, percentage_spent`. **Three of those four properties do
not exist**, so the table renders a blank category and `S/ 0.00` in every row, and
`$budgetDeviation->sum('real')` in the footer renders `S/ 0.00` too. Fixing only `sum('real')` would
leave the table blank.

Bound fix, at the service boundary (Financial Analysis owns the shape it publishes):
`getBudgetDeviation()` additionally returns `variance` (`budgeted - spent`) and `status`
(`spent > budgeted ? 'Excedido' : 'Dentro'`) per row; the blade is corrected to `$item->name` and
`$item->spent` and the footer to `sum('spent')`. This stays a defect fix on a command already being
touched (ADR-0005), not a redesign of the report.

---

## 9. File changes

| File | Action | Description |
|---|---|---|
| `database/migrations/{ts}_create_coaching_observations_table.php` | Create | The table in §4. Additive; `down()` drops it. |
| `app/Models/CoachingObservation.php` | Create | Model with casts. |
| `app/DTOs/Coaching/CategoryMonthSnapshot.php` | Create | `categoryId, name, monthlyBudget, spent`. |
| `app/DTOs/Coaching/PaceObservation.php` | Create | `subjectKey, categoryId, name, band, isLumpy, spent, budget, projected, dayOfMonth, cause`. |
| `app/DTOs/Coaching/MonthCursor.php` | Create | `day, daysInMonth, periodMonth, startsAt, endsAt`. Built once from `config('app.timezone')`. |
| `app/DTOs/Coaching/PaceThresholds.php` | Create | `minDayForProjection, overrunMargin, lumpyShare, maxObservations`. From config. |
| `app/DTOs/Coaching/CoachingScope.php` | Create | `sweep()` / `forCategory(int $id, float $amount)`. |
| `app/Services/Coaching/PaceEvaluator.php` | Create | Pure. Fork 1's testable core. |
| `app/Services/Coaching/SpokenObservationLedger.php` | Create | Claim-by-insert, band ladder, `confirmSent()`. |
| `app/Services/Coaching/CoachingMessageComposer.php` | Create | §6. |
| `app/Services/Coaching/FinancialCoachingService.php` | Create | **The owning service.** `speak(User, CoachingScope): bool`. |
| `app/Console/Commands/RunCoachingSweep.php` | Create | `app:run-coaching-sweep` + summary counters. |
| `config/coaching.php` | Create | Env-backed thresholds, channel preference, kill switch. |
| `app/Repositories/CategoryRepository.php` (+ contract) | Modify | `expenseBudgetSnapshotsForMonth()`. |
| `app/Repositories/TransactionRepository.php` (+ contract) | Modify | `topMerchantsForCategoryMonth()`, `largestExpenseForCategoryMonth()`. |
| `app/Services/Capture/ChannelIdentityResolver.php` | Modify | `preferredIdentityFor()`, `userIdsReachableOn()`. |
| `app/Jobs/ProcessTelegramCapture.php` | Modify | Guarded `speak()` after the ✅ confirmation. |
| `config/database.php` | Modify | Pin `pgsql.timezone` (D3). |
| `routes/console.php` | Modify | Add the sweep; later remove the daily summary. |
| `app/Console/Commands/SendSummaryTransactionsByDay.php` | **Delete** | Slice 6, never before slice 4. |
| `app/Console/Commands/SendSummaryTransactionByMonth.php` | Modify | Remove the WhatsApp block (takes `sum('real')` with it); keep the email. |
| `app/Services/FinancialReportService.php` | Modify | `getBudgetDeviation()` returns `variance` and `status`. |
| `resources/views/emails/summary_month.blade.php` | Modify | `$item->name`, `$item->spent`, `sum('spent')`. |
| `app/Services/Capture/CaptureChannel.php`, `TelegramChannel.php`, `WhatsAppChannel.php` | **Untouched** | `reply()` already serves an unprompted, plain-text send. |
| `get_monthly_category_budget_report`, `get_pareto_monthly_report`, `get_transactions_by_detail`, `v_unified_transactions` | **Untouched** | Read only. No new SQL object is created. |

---

## 10. Testing strategy

| Layer | What | How |
|---|---|---|
| Unit (`tests/Unit`) | `PaceEvaluator`: 340/400 on day 12 → `projected_over`; same on day 28 → nothing; day 3 → nothing unless already over; lumpy ≥50% → no projection; `spent <= 0` skipped; income categories absent; ordering and the 3-cap. | Plain PHP, DTOs in, DTOs out, **no database, no `now()`**. This test suite is the acceptance criterion for D1. |
| Unit | `CoachingMessageComposer`: every band's copy; refund guard omits the share; plural agreement; **asserts no output ever contains a Pareto bucket share**. | Pure. |
| Feature (`tests/Feature`, real PostgreSQL) | `SpokenObservationLedger`: second claim of the same band returns `false`; a worse band claims; a better band is suppressed by the ladder; blindness once per month; a new `period_month` starts empty. | `RefreshDatabase`. |
| Feature | Sweep and capture race: claim twice concurrently → exactly one row, one send. Assert the enclosing transaction survives the caught violation (the 25P02 regression this pattern exists to prevent). | Pest, `Http::fake()`. |
| Feature | Reverse lookup: Telegram preferred over WhatsApp for a dual-identity user; a WhatsApp-only user is absent from `userIdsReachableOn(['telegram'])` and counted in the summary. | `RefreshDatabase`. |
| Feature | Repository cause queries: a single-transaction merchant **is** returned (the `HAVING` hole); month range is half-open; `type_transaction = 'expense'` only. | Real DB. |
| Feature | Capture path: a transaction that does not worsen the band produces no send; one that does produces exactly one. | `Http::fake()`. |
| Feature | `SendSummaryTransactionByMonth`: the email renders a non-zero real spend and a non-blank category; no WhatsApp call is made. | `Mail::fake()`, `Http::fake()`. |
| Static | Larastan level 6 clean; `pint --test` clean. | `./vendor/bin/phpstan analyse` |

---

## 11. Threat matrix

**N/A** — no routing change, no shell command, no subprocess, no VCS/PR automation, no
executable-file classification and no process-integration boundary is introduced. The one external
call is an outbound `sendMessage` through the **existing, unmodified** `TelegramChannel` adapter.

Two adjacent safety constraints are nevertheless bound as design requirements, both traced to QA-4
(rank 1) and QA-3:

| Constraint | Requirement |
|---|---|
| Injection through user-controlled merchant names | `reply()` MUST continue to post without `parse_mode` (D10). A composer test asserts the payload carries no `parse_mode`. |
| Unprompted send volume | At most one message per user per sweep run and one per capture; the ledger's unique index is the hard ceiling, and `COACHING_MAX_OBSERVATIONS=0` is the runtime kill switch. |

---

## 12. Migration / rollout

1. **Slices 1–3** (evaluator, data access, ledger + migration) ship **no user-visible behaviour**.
   The migration is additive and safe to deploy alone.
2. **Slice 4** adds the sweep and its schedule entry. First run in production is a `--dry-run`
   (compose and log, do not send, do not claim) so the owner reads the messages before anyone does.
   `--dry-run` must skip the claim, otherwise it burns the bands it was meant to preview.
3. **Slice 5** adds the capture-time check.
4. **Slice 6** retires the daily summary and repairs the monthly one. **Must not land before slice 4.**
5. The `config/database.php` timezone pin ships with **slice 2** (the first slice whose correctness
   depends on month bucketing) and carries the before/after report comparison described in D3.

---

## 13. Open questions

None blocking. Two decisions carry stated, accepted costs rather than uncertainty:

- [ ] The `pgsql.timezone` pin changes month bucketing for existing reports if the database server is
      not already `America/Lima`. The verification step in D3 turns this from an unknown into a
      checked fact before merge. Could not be verified from source alone.
- [ ] `sent_at` is not an acknowledgement. Turning it into one requires `reply()` to return a
      result, which is a port change this change deliberately does not make.
- [ ] The monthly email's broken blade (§8) is wider than the proposal recorded. It is fixed here
      because the command is already being touched (ADR-0005); if the owner prefers to isolate it,
      it splits cleanly out of slice 6.
