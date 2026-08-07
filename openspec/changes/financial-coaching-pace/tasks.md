# Tasks: Pace-aware spending coach

> Size note: exceeds the default 530-word budget deliberately, mirroring `design.md`'s own
> exception — the owner's sequencing constraints, the claim-by-INSERT precedent, and the
> monthly-email defect require file-level precision that a terser checklist would lose.

## Review Workload Forecast

| Field | Value |
|---|---|
| Estimated changed lines | ~950 (per `proposal.md`) |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | 6 slices, separate commits on **one** branch |
| Delivery strategy | auto-chain |
| Chain strategy | stacked-to-main |

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Commit | Focused test command | Runtime harness | Rollback boundary |
|---|---|---|---|---|---|
| 1 | Pace evaluator, pure PHP, no DB | 1 (~220 ln) | `php artisan test --filter=PaceEvaluatorTest` | N/A — pure unit, no DB/queue | Delete `app/Services/Coaching/PaceEvaluator.php` + DTOs |
| 2 | Category-month data access + timezone pin | 2 (~130 ln) | `php artisan test --filter=Coaching` | Real PostgreSQL (`wajaycha-1`) | Delete timezone line; revert repository methods |
| 3 | Spoken-observation memory | 3 (~150 ln) | `php artisan test --filter=SpokenObservationLedgerTest` | Real PostgreSQL, concurrent claim test | `Schema::dropIfExists('coaching_observations')` |
| 4 | Scheduled sweep | 4 (~230 ln) | `php artisan test --filter=RunCoachingSweepTest` | `php artisan app:run-coaching-sweep --dry-run` against seeded data | Remove schedule entry + command; `COACHING_ENABLED=false` |
| 5 | Capture-time check | 5 (~120 ln) | `php artisan test --filter=ProcessTelegramCaptureCoachingTest` | `Http::fake()` Telegram webhook replay | Revert the guarded `speak()` call in the job |
| 6 | Retire daily summary; repair monthly | 6 (~100 ln) | `php artisan test --filter=SendSummaryTransactionByMonthTest` | `php artisan app:send-summary-transaction-by-month` against seeded data | Restore both commands + both schedule entries |

Unit 6 **must not land before Unit 4** (overlap window, design §8).

## Phase 1: Pace evaluation core (commit 1)

- [x] 1.1 Create DTOs `app/DTOs/Coaching/{CategoryMonthSnapshot,PaceObservation,MonthCursor,PaceThresholds,CoachingScope}.php` — readonly, structural, no triangulation needed.
- [x] 1.2 RED `tests/Unit/Coaching/PaceEvaluatorTest.php`: 340/400 day 12/31 → `projected_over`; same level day 28 → nothing; day 3 already over → `over_budget`; day 3 under budget + 12%-over projection → nothing (pre-day-5); day 12 projection 4% over → nothing (margin); 70% single-transaction + over budget → `over_budget`, `isLumpy=true`, no `projected`; 70% single-transaction under budget → nothing; income category → nothing; `spent<=0` → skipped; `monthly_budget=0` handled by caller, not the evaluator; severity ordering + 3-cap.
- [x] 1.3 GREEN implement `app/Services/Coaching/PaceEvaluator.php::evaluate()` per design §5.1's 5-step order table. No facades, no Eloquent, no `now()`.

## Phase 2: Category-month data access + timezone pin (commit 2)

- [x] 2.1 Pin `config/database.php` → `pgsql.timezone = env('DB_TIMEZONE', 'America/Lima')` (D3).
- [x] 2.2 RED `tests/Feature/Coaching/DatabaseTimezoneTest.php` asserting the live PostgreSQL session reports `America/Lima` (e.g. `DB::selectOne('SHOW TIME ZONE')`) — proves the pin, not just its presence in the file.
- [ ] 2.3 Manually run the D3 before/after comparison of `get_monthly_category_budget_report` totals for current and previous month against a production copy; record result in the PR description.
  > **Not implementable by `sdd-apply`.** This is a release-gate step for the owner: it requires a production database copy, which does not exist in this environment. Left unchecked and un-faked on purpose — do not tick this box without actually running the comparison against production data before merge.
- [x] 2.4 RED `tests/Feature/Coaching/CategoryRepositoryExpenseSnapshotsTest.php`: only `type = 'expense'` categories are returned (the enum is income/expense/transfer; there is no savings value); throws when `total_records` exceeds `config('coaching.max_categories')` (500); leaf categories only.
- [x] 2.5 GREEN implement `CategoryRepository::expenseBudgetSnapshotsForMonth()` (+ contract) calling `get_monthly_category_budget_report(1, config('coaching.max_categories'), …)`.
- [x] 2.6 RED `tests/Feature/Coaching/TransactionRepositoryCauseQueriesTest.php`: a single-transaction merchant **is** returned (proves no `HAVING` hole); month range is half-open; `type_transaction='expense'` only; reads `v_unified_transactions` (never `get_transactions_by_detail()`).
- [x] 2.7 GREEN implement `TransactionRepository::topMerchantsForCategoryMonth()` and `largestExpenseForCategoryMonth()` (+ contract). No `SELECT *`, explicit columns.

## Phase 3: Spoken-observation memory (commit 3)

- [ ] 3.1 Create migration `database/migrations/{ts}_create_coaching_observations_table.php` per design §4 (columns, Spanish comments, `unq_coaching_observations_user_period_subject_band`, `idx_coaching_observations_spoken_at`). `down()` drops the table.
- [ ] 3.2 Create `app/Models/CoachingObservation.php` with `$fillable` and the casts listed in §4.
- [ ] 3.3 RED `tests/Feature/Coaching/CoachingObservationSchemaTest.php`: inserting a row with `subject_key = NULL` raises a DB-level exception — proves the NOT NULL guard that exists because Postgres treats NULLs as distinct in a unique index.
- [ ] 3.4 GREEN — none beyond 3.1; run 3.3 against real Postgres and confirm it fails without the constraint and passes with it.
- [ ] 3.5 RED `tests/Feature/Coaching/SpokenObservationLedgerTest.php`: second claim of the same `(user_id, period_month, subject_key, band)` returns `false`; a caught `UniqueConstraintViolationException` does not poison the enclosing `DB::transaction` (assert a subsequent write in the same outer transaction still succeeds — the 25P02 regression); two concurrent claims of the same band produce exactly one `true` and the loser returns cleanly, no throw; a worse band claims after a better one; a better band is suppressed by `highestBandFor()`; blindness claims once per month; a new `period_month` starts with an empty ladder.
- [ ] 3.6 GREEN implement `app/Services/Coaching/SpokenObservationLedger.php` — `claim()` reusing the exact `DB::transaction(fn () => Model::create([...])) / catch (UniqueConstraintViolationException)` pattern from `ChannelUpdateDeduplicator::claim()` (no fourth variant), plus `highestBandFor()` and `confirmDelivered()`.

## Phase 4: Scheduled sweep (commit 4)

- [ ] 4.1 `config/coaching.php` **already exists** — created early by task 2.4/2.5 (Phase 2) because that task's GREEN needed `config('coaching.max_categories')` before this task's original position in the plan. It currently holds only `max_categories=500`. **Extend**, do not create: add `min_day_for_projection=5`, `overrun_margin=0.10`, `lumpy_share=0.50`, `max_observations_per_message=3`, `channels=['telegram']`, `COACHING_ENABLED` kill switch, `COACHING_MAX_OBSERVATIONS`.
- [ ] 4.2 RED `tests/Feature/Capture/ChannelIdentityResolverReachTest.php`: `preferredIdentityFor()` prefers Telegram over WhatsApp for a dual-identity user; `userIdsReachableOn(['telegram'])` excludes a WhatsApp-only user.
- [ ] 4.3 GREEN implement `preferredIdentityFor()`, `userIdsReachableOn()` on `app/Services/Capture/ChannelIdentityResolver.php` (chunked `LazyCollection`).
- [ ] 4.4 RED `tests/Unit/Coaching/CoachingMessageComposerTest.php`: all four band shapes (§6); refund guard omits the share when merchant total exceeds `spent`; singular/plural agreement; **asserts no output ever contains `actual_percentage` or a Pareto bucket share** (D8, non-negotiable).
- [ ] 4.5 GREEN implement `app/Services/Coaching/CoachingMessageComposer.php`. No `parse_mode` payload (D10) — assert the outbound payload carries none.
- [ ] 4.5b RED `tests/Unit/Coaching/BlindnessDetectorTest.php`: a category with spend and `monthly_budget = 0` is reported as unwatchable; a category with `monthly_budget = 0` and **no** spend is not reported at all; a budgeted category never appears; only `type = 'expense'` is considered; the report is one observation per user per month keyed `blindness`, never one per category.
- [ ] 4.5c GREEN implement blindness detection over the same snapshots the evaluator receives. **It is deliberately NOT in `PaceEvaluator`** — design §5.1 marks `blind` as "not comparable", so it is not a band on the pace ladder and must not be ranked against one. Without this task the owner's explicit decision — that the coach reports what it cannot watch instead of staying silent — has no owner and would be lost between phases.
- [ ] 4.6 RED `tests/Feature/Coaching/FinancialCoachingServiceSweepTest.php`: `claim()` runs **before** `TelegramChannel::reply()` (assert via call-order spy/sequence, D7); a failed send (`Http::fake()` non-2xx) does **not** un-claim — the band stays spoken, `delivered_at` stays NULL; sweep-vs-capture race across both entry points produces exactly one message; `speak()` returns `false` and sends nothing when nothing crosses.
- [ ] 4.7 GREEN implement `app/Services/Coaching/FinancialCoachingService.php::speak()`: snapshot → evaluate → ladder → cause → claim → compose → send → confirm.
- [ ] 4.8 RED `tests/Feature/Console/RunCoachingSweepTest.php`: `--dry-run` composes and logs but calls `claim()` **zero** times (asserts no `coaching_observations` row is inserted); a normal run claims and sends; the summary line counts total/reachable/no-channel/sent/silent, and a WhatsApp-only user is counted under "sin canal de coaching", never silently dropped.
- [ ] 4.9 GREEN implement `app/Console/Commands/RunCoachingSweep.php` (`app:run-coaching-sweep {--dry-run}`).
- [ ] 4.10 Add `Schedule::command(RunCoachingSweep::class)->dailyAt('20:00')` to `routes/console.php`. Leave `SendSummaryTransactionsByDay`'s `20:08` entry untouched — the deliberate overlap window.

## Phase 5: Capture-time check (commit 5)

- [ ] 5.1 RED `tests/Feature/Jobs/ProcessTelegramCaptureCoachingTest.php`: a captured expense that crosses the pace line sends exactly one coaching message **after** the ✅ confirmation, evaluated as a counterfactual (with/without the new transaction); a capture that does not cross the line sends confirmation only; an income transaction is never coached; a transaction with `category_id = NULL` is never coached; a coaching exception is caught/logged, the `Transaction` stays registered, the confirmation still sends, and the job is **not** retried.
- [ ] 5.2 GREEN modify `app/Jobs/ProcessTelegramCapture.php`: guarded `speak(user, CoachingScope::forCategory($categoryId, $amount))` inside `try/catch (Throwable)` after the confirmation reply.

## Phase 6: Retire the daily summary; repair the monthly one (commit 6 — not before commit 4)

- [ ] 6.1 RED `tests/Feature/Services/FinancialReportServiceBudgetDeviationTest.php`: `getBudgetDeviation()` rows carry non-null `variance` (`budgeted - spent`) and `status` (`spent > budgeted ? 'Excedido' : 'Dentro'`).
- [ ] 6.2 GREEN modify `app/Services/FinancialReportService.php::getBudgetDeviation()` to compute `variance` and `status`.
- [ ] 6.3 RED `tests/Feature/Console/SendSummaryTransactionByMonthTest.php`: the queued email (`Mail::fake()`) carries a non-zero real spend and a non-blank category name; asserts **zero** `Http::fake()` calls (WhatsApp push removed).
- [ ] 6.4 GREEN remove the WhatsApp block from `app/Console/Commands/SendSummaryTransactionByMonth.php`; fix `resources/views/emails/summary_month.blade.php` to `$item->name`, `$item->spent`, `sum('spent')`, `sum('variance')`.
- [ ] 6.5 Delete `app/Console/Commands/SendSummaryTransactionsByDay.php`, `app/Mail/NotificationSummaryByDay.php` and both entries in `routes/console.php`. No RED test — pure deletion. Verify `php artisan list` no longer shows `app:send-summary-transactions-by-day`.

## Definition of Done

- [ ] Every RED/GREEN pair above passes under `php artisan test`.
- [ ] `./vendor/bin/phpstan analyse` (Larastan level 6) clean; `./vendor/bin/pint --test` clean.
- [ ] D3 before/after timezone comparison (task 2.3) recorded before merge.
- [ ] No composed message ever contains `actual_percentage` or a Pareto bucket share (4.4).
- [ ] `--dry-run` claims nothing (4.8).
- [ ] Every observation with `spent > 0` names the category and at least one merchant (D9 invariant, proven by 4.4/4.7).
- [ ] `app:send-summary-transactions-by-day` no longer exists and is off the schedule (6.5).
- [ ] Commit 6 lands strictly after commit 4 in history.

## Deferred

| Item | Why |
|---|---|
| `users.timezone` column / per-user `MonthCursor` | Design D3 boundary condition; single-market Peru assumption, explicitly out of scope |
| Fixing `get_transactions_by_detail()`'s `HAVING COUNT>1` | Would change the SPA's recurring-transactions view; worked around via new repository methods (D5), not fixed |
| `delivered_at` as a real delivery acknowledgement | Requires a `reply()` port change; deliberately not made here |
| Technical-debt items 1, 2, 10 (nine legacy SQL functions) at large | Proposal non-goal; only D1's bounded exception is touched |
| `CategorizationService` unit tests | Not touched by this change; no scope added |
| Any SPA surface change | Non-goal |
| WhatsApp coaching | Template-gated per ADR-0007; Telegram only |
| Interactive coaching / buttons / reply handling | Shelved with `financial-coaching-clarify` |
