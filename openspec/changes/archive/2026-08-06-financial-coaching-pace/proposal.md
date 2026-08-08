# Proposal — Pace-aware spending coach

- **Change:** `financial-coaching-pace`
- **Date:** 2026-08-06
- **Owning subdomain:** Financial Coaching (first behaviour) — touches Ingestion and Notification
- **Decided by:** [ADR-0007](../../../../docs/decisions/0007-telegram-primary-conversational-channel.md)
- **Exploration:** [`exploration.md`](exploration.md) — mirrored from Engram `sdd/financial-coaching-pace/explore`
- **Supersedes intent of:** `financial-coaching-clarify` (shelved after propose — the owner already sets categories himself)

## Intent

Financial Coaching exists in the [context map](../../../../docs/domain/context-map.md) and **owns nothing**. This is its first code, and the owner has stated what it is for: make him conscious of his spending, using the budgets and Pareto that already exist.

The thing it must add is **pace**. "80% of food on day 8" and "80% on day 28" are the same level and opposite situations; a fixed threshold cannot tell them apart, and nothing in the system computes elapsed-versus-remaining month per category today. The one precedent is fake: `get_summary_transaction_by_day()` has the right shape but a hardcoded `v_amount_total NUMERIC := 2000;` whole-account budget, reached by raw `DB::select` from a command hardcoded to `$userId = 1`.

Two things make this coaching rather than notification: it speaks **unprompted** ([QA-8](../../../../docs/quality/quality-attributes.md#qa-8--proactive-engagement), the driver ADR-0007 was written for), and it **states its own blindness** instead of letting silence read as "you're fine".

## Scope

### In scope

1. **One pace evaluation** — a single "is there anything worth saying?" decision over the existing budget report plus elapsed and remaining days of the month, per category, per user.
2. **Two entry points, one voice.** A scheduled sweep (QA-8) and a capture-time check after a bot capture registers successfully. Both consult the same evaluation; neither has rules of its own.
3. **Fact and cause, then stop.** Shape: *"Comida: 340 de 400 el día 12, a este ritmo cerrás en 850. El 60% son 4 pedidos de delivery."* No advice, no suggestion, no next step.
4. **Lumpiness derived from the spending itself.** When one transaction dominates a category's month, projection is meaningless — report level and name that transaction instead. No new field, no manual marking, no reliance on the `Fijos`/`Variables` convention, which exploration confirmed is unenforced.
5. **Report the blindness.** A category with `monthly_budget = 0` can never raise a pace warning — zero is the schema default and `UserObserver::created()` leaves every seeded category there. Spend in unbudgeted categories is surfaced as *something the coach cannot watch*.
6. **Remember what was already said**, so the two entry points never repeat each other and a speaking budget is enforceable. Nothing anywhere holds this state today.
7. **Reverse identity lookup** — `ChannelIdentityResolver` only goes account → user. A sweep needs user → where to reach them.
8. **Dispose of the two existing scheduled summaries** (fork 2 below).
9. **User-facing strings in Spanish**, matching the existing bot replies.

### Out of scope — explicit non-goals

- **Advice of any kind.** Not "reducí delivery", not "te quedan S/ 60 por día". The coach narrates; the user decides.
- **`actual_percentage` narrated as behaviour, anywhere.** It is `SUM(budget in bucket) / SUM(budget of all non-income categories)` — budget-allocation weight. Nothing in this system computes a bucket's share of actual spend. The genuine declared-versus-real pair is `percentage` against `percentage_spent`.
- **Any dialogue turn.** No buttons, no `callback_query`, no reply handling. The coach speaks and stops. Interactive coaching stays shelved with `financial-coaching-clarify`.
- **New schema for budgets or lumpiness.** No "is fixed" flag, no per-category cadence column.
- **Fixing `get_transactions_by_detail()`'s `HAVING COUNT(t.id) > 1`.** It silently drops single-transaction merchants, so a category blown by one purchase has no causal breakdown. Changing it would change the SPA's recurring-transactions view. Worked around, not fixed — see risks.
- **WhatsApp coaching.** Its 24-hour window makes unprompted sends template-gated (ADR-0007). Telegram only.
- **Technical-debt items 1, 2 and 10 at large.** Item 10 informs fork 1; nine SQL functions are not being wrapped here.
- **Any SPA surface**, and any change to the categorization cascade.

## Capabilities

### New capabilities

- `financial-coaching`: the pace evaluation, the lumpy-versus-linear rule, blindness reporting, the spoken-observation memory, the speaking budget, and the definition of a valid silence.

### Modified capabilities

- `ingestion`: a successful bot capture may now be followed by at most one coaching message on the same channel, emitted by Coaching, not by the capture path.

## Approach

**One owning service** (config rule: one Service per subdomain) exposes a single evaluation: `(user, reference date) → ordered observations`. Each observation carries a kind (`pace`, `level`, `blind`), the numbers, and an optional cause. The scheduled sweep and the capture-time check differ only in their filter and their speaking budget — never in their rules. That is what makes "one voice" structural instead of a convention.

**Arithmetic**, per budgeted category, at day `d` of a month of `D` days: expected `= budget × d/D`; projected close `= spent × D/d`. An observation exists when the projection overruns the budget by a margin, or when spend already exceeds it. Both inputs already exist in `get_monthly_category_budget_report`, correctly scoped by `user_id` and by `date_operation` — which is precisely what makes pace measurable.

**Lumpiness** comes from the transactions, not from a label: when the largest single transaction is a dominant share of the category's month-to-date spend, emit a level observation naming that transaction and do **not** project.

**Cause** comes from `get_transactions_by_detail()`, which already returns merchant frequency and amount within a category-month — the "4 pedidos de delivery" shape. When it returns nothing, the coach states the level without a cause. It never invents one.

**Memory and the speaking budget** ([QA-1](../../../../docs/quality/quality-attributes.md#qa-1--capture-friction) is what this risks):

| Rule | Value |
|---|---|
| Capture-time | At most 1 message, only about the category the new transaction landed in, only if that transaction is what crossed the line |
| Sweep | At most 1 message per user per run, carrying at most N observations ordered by severity |
| Re-speaking | Only when a category crosses into a worse band (on pace → projected over → already over). Never the same band twice in a month |
| Blindness | At most once per user per month |
| Nothing to say | **Send nothing.** There is no "todo bien" message |
| Unreachable user | Skipped silently. No error, no retry — `reply()` has no retry model on either channel |

**Reach.** `reply()` on both channels is already a context-free send, so it is reusable for an unprompted message as it stands — **no port change is needed**, unlike the shelved clarify change. What is missing is the reverse lookup. `channel_identities` is unique on `(channel, external_id)`, **not** on `user_id`, so a user may hold both a WhatsApp and a Telegram identity; the resolver must prefer Telegram deliberately, and a WhatsApp-only user is not coached.

**Trusting the numbers** ([QA-2](../../../../docs/quality/quality-attributes.md#qa-2--categorization-accuracy), [QA-3](../../../../docs/quality/quality-attributes.md#qa-3--financial-data-integrity)): the coach speaks about categorized transactions, so a miscategorisation becomes a spoken error. The mitigation is in the message shape itself — naming the category *and* the merchants behind the number makes a wrong reading visibly wrong and correctable, instead of an unattributable accusation.

## Fork 1 — where the pace arithmetic lives

| Option | For | Against |
|---|---|---|
| **A — PHP**, in the owning Coaching service, over rows the existing repositories already return | Testable without a database; tuning a threshold is a config change, not a migration; no set-based work moves out of SQL | One more consumer of report functions that still bypass the repository layer in places |
| **B — a new SQL function** `get_category_pace_report(...)` | Matches the codebase's dominant pattern; one round trip | Becomes the **tenth** business-rule SQL function ([technical debt item 10](../../../../docs/architecture/technical-debt.md#10-business-logic-split-between-php-and-postgresql-functions), "cannot be tested without a live database"); every threshold tweak is a migration |

**Recommendation: A.** Three reasons, in order of weight. First, the pace rules are *product judgement* — what margin is worth speaking about, what counts as lumpy, when re-speaking is warranted — and they will be tuned repeatedly by the owner reacting to real messages; that belongs where it can be changed and unit-tested, not in a migration. Second, item 10 is a debt the workspace has already written down and diagnosed; adding a tenth function on a **new** subdomain's first commit deepens it deliberately rather than incidentally. Third, there is no performance argument to trade against: the aggregation is already done in SQL, and pace is per-row arithmetic over roughly twenty rows per user, at ~100 users.

**Bounded exception:** the *cause* breakdown and the *largest-transaction share* are genuinely set-based and stay in the database — but reached through repository methods, and any new SQL object must be pure aggregation with no threshold baked in, documented per `.agents/rules/02-database-dba.md`. Note the largest-transaction share **must not** come from `get_transactions_by_detail()`, whose `HAVING COUNT > 1` drops exactly the single large purchase the lumpy rule is looking for.

Binding decision: `sdd-design`.

## Fork 2 — the two existing scheduled summaries

Current state, both scheduled in `routes/console.php`, both hardcoded to `$userId = 1` and the literal string `'+51 992 291 220'`, both pushing through WhatsApp directly:

- `SendSummaryTransactionsByDay` reads `get_summary_transaction_by_day()`, whose budget is a hardcoded `v_amount_total NUMERIC := 2000;` — a whole-account number that belongs to nobody. Its "meta de gasto diario" and "nuevo monto a gastar por día" are *the pace arithmetic this change builds honestly*, computed from a fiction.
- `SendSummaryTransactionByMonth` calls `$budgetDeviation->sum('real')`; `FinancialReportService::getBudgetDeviation()` selects the column as `spent`. **"Gasto Real" has always rendered `S/ 0.00`.**

**Recommendation: absorb the daily one, repair and demote the monthly one.**

1. **Delete `SendSummaryTransactionsByDay`** and its schedule entry. Everything it says is a worse version of what the coach says, from a budget that is invented. Fixing it means deciding what a whole-account monthly budget is — a product question the per-category budgets already answer better — and then maintaining two pace implementations. This is the direct collision with the owner's one-voice requirement: a user told two different budgets on the same day stops believing both, which costs more (QA-2, QA-3) than the message is worth.
2. **Keep `SendSummaryTransactionByMonth`'s email, fix `sum('real')` → `sum('spent')`, and remove its WhatsApp push.** A month-close report the user opens is not the coach speaking unprompted; it does not compete for the same voice. Its WhatsApp push does, and it goes.
3. **Rejected — "leave them":** it leaves a defective message contradicting the coach on the same day, on the same class of channel.
4. **Rejected — "fix them in place":** it builds pace twice and requires inventing an account-level budget.

**Subdomain-crossing justification** (config rule: a change must not span two owning services without justification): the Notification touch is a deletion and a one-line defect fix, not new logic. The one-voice requirement is unsatisfiable while a second scheduler pushes contradicting numbers, so the disposition cannot be deferred to a later change without shipping a known contradiction.

**Cost accepted:** the owner loses the daily WhatsApp message the day this lands. Mitigated by slice ordering — the deletion may not land before the sweep works.

## Affected areas

| Area | Impact | What changes |
|---|---|---|
| `app/Services/Coaching/` (owning service) | New | Pace evaluation, lumpy rule, blindness, speaking budget, message composition |
| `app/Repositories/CategoryRepository.php` | Modified | Budget-report access for the coach; it already calls the SQL function |
| `app/Repositories/TransactionRepository.php` | Modified | Cause breakdown and largest-transaction share for a category-month |
| `app/Services/Capture/ChannelIdentityResolver.php` | Modified | Reverse lookup user → identity, Telegram preferred |
| `app/Jobs/ProcessTelegramCapture.php` | Modified | Capture-time check after `RegisterCapturedTransactionAction` succeeds |
| New console command + `routes/console.php` | New/Modified | The scheduled sweep |
| Migration + model, spoken observations | New | Purely additive |
| `app/Console/Commands/SendSummaryTransactionsByDay.php` | **Removed** | Fork 2 |
| `app/Console/Commands/SendSummaryTransactionByMonth.php` | Modified | `sum('spent')`; WhatsApp push removed; email kept |
| `app/Services/Capture/CaptureChannel.php`, `TelegramChannel.php` | **Untouched** | `reply()` already serves an unprompted send |
| `get_monthly_category_budget_report`, `get_pareto_monthly_report` | **Untouched** | Read, never modified |

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| A miscategorised transaction becomes a spoken accusation (QA-2) | Medium | Every message names the category **and** the merchants behind the number, so a wrong reading is visibly wrong and correctable |
| The cause breakdown is missing exactly when a category was blown by one purchase (`HAVING COUNT > 1`) | Certain | That is the lumpy case: report level and name the transaction from raw transaction data, never from that function. Never claim a cause the query cannot produce |
| The coach becomes noise and damages QA-1 | Medium | Speaking budget, band-based re-speaking, spoken-observation memory, silence as a first-class outcome |
| Every category sits at `monthly_budget = 0`, so the coach can only report blindness | Medium | Designed behaviour — the blindness report *is* the fix for silence reading as "you're fine". It also makes setting budgets the obvious first act |
| Deleting the daily summary removes a message the owner receives today | Certain | Slice ordering: the deletion lands at or after the working sweep, never before |
| Early-month projection amplifies noise — `spent × D/d` at `d=1` projects 30× | High | A minimum-elapsed-days floor before any projection; before it, only actual overspend speaks |
| Month boundary and day-of-month are timezone-sensitive; `date_operation` is `timestamptz` | Medium | Day-of-month is resolved in one declared timezone, consistently for both entry points. Pace is meaningless if day 1 is ambiguous |
| Sweep and capture-time check fire concurrently and both speak; no per-user job serialisation exists | Medium | The spoken-observation record is written under a database uniqueness constraint, not a read-then-write |
| A WhatsApp-only user is never coached | Certain | Deliberate, per ADR-0007. Stated, not hidden |
| `v_unified_transactions.matched_yape_id` is a per-row correlated subquery always executed | Low | Noted only; not touched here |

## Rollback

- **Every coaching slice** reverts by reverting its commit. The only schema step — the spoken-observation table — is **purely additive**; drop it. No existing column, row, migration or SQL function is modified.
- **Without a deploy:** remove the sweep from the schedule, and set the per-user message cap to `0` to silence both entry points, restoring today's capture behaviour exactly.
- **Fork 2** is pure code. Reverting restores both commands; `routes/console.php` restores both schedule entries.
- Nothing here touches the Gemini integration, queue payloads, or the categorization cascade.

## Success criteria

- [ ] A category at 340/400 on day 12 produces one message with the level, the day, the projected close, and the merchants behind it.
- [ ] The same category at the same level on day 28 produces **no** message.
- [ ] A category blown by one transaction reports level and names that transaction; it does not project.
- [ ] Spend in unbudgeted categories is reported as unwatchable, at most once per month.
- [ ] The sweep is silent about what a capture already said, and vice versa — proven by test.
- [ ] A run with nothing worth saying sends nothing.
- [ ] No message contains `actual_percentage` in any form.
- [ ] The monthly email shows a non-zero real spend; `sum('real')` no longer exists.
- [ ] `app:send-summary-transactions-by-day` no longer exists and is off the schedule.
- [ ] A user with no Telegram identity is skipped without error.
- [ ] The pace evaluation is unit-tested with no database (fork 1's whole point).

## Review budget forecast

**400-line budget risk: High.** Estimated ~950 authored lines. **Decision needed before apply: No** — `auto-chain` is cached. **Chained PRs recommended: Yes**, as separate commits on **one** branch (`stacked-to-main`), carrying forward the owner's explicit feedback that many chained branches are expensive.

| # | Slice | Kind | Est. lines |
|---|---|---|---|
| 1 | Pace evaluation core — pure evaluator, lumpy rule, bands + unit tests | behaviour + tests | ~220 |
| 2 | Category-month data access — budget report, cause breakdown, largest-transaction share | data access | ~130 |
| 3 | Spoken-observation memory — additive migration, model, uniqueness rule | schema + behaviour | ~150 |
| 4 | Scheduled sweep — reverse identity lookup, composition, Telegram send | integration | ~230 |
| 5 | Capture-time check | integration | ~120 |
| 6 | Retire the daily summary; repair and de-WhatsApp the monthly one | cleanup + bugfix | ~100 |

Slice 6 **must not land before slice 4**. Slice 1 is independently reviewable and revertible; slices 1–3 ship no user-visible behaviour, which is intentional — the first message the coach ever sends should be reviewed on its own.

## Open questions for the owner

Non-blocking. The proposal proceeds on the assumption stated in each.

1. **What margin makes a category worth speaking about?** Assumed: projected close exceeds budget by **≥ 10%**, or actual spend already exceeds budget.
2. **What counts as lumpy?** Assumed: one transaction is **≥ 50%** of the category's month-to-date spend → report level, do not project.
3. **How early may it project?** Assumed: **day 5**. Before that, only actual overspend speaks.
4. **How many observations may one message carry?** Assumed: **3**, ordered by severity; the rest stay silent.
5. **When may it re-speak about the same category in the same month?** Assumed: **only on crossing into a worse band** (on pace → projected over → already over), never the same band twice.
6. **How often does the sweep run?** Assumed: **daily, once, at a fixed hour**. Daily is what makes "day 12" mean anything; weekly would make the phrase a lie half the time.
7. **Is the monthly-close email kept at all?** Assumed **yes**, repaired, email only. Turning month-close into a coach message is a separate change.
8. **Which timezone defines day-of-month?** Assumed **America/Lima**.
9. **Should the coach ever speak about income or savings categories?** Assumed **no** — expense categories only. Pace over a savings target is a different, inverted rule.
