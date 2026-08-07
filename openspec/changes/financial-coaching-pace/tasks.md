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

- [x] 3.1 Create migration `database/migrations/{ts}_create_coaching_observations_table.php` per design §4 (columns, Spanish comments, `unq_coaching_observations_user_period_subject_band`, `idx_coaching_observations_spoken_at`). `down()` drops the table.
- [x] 3.2 Create `app/Models/CoachingObservation.php` with `$fillable` and the casts listed in §4.
- [x] 3.3 RED `tests/Feature/Coaching/CoachingObservationSchemaTest.php`: inserting a row with `subject_key = NULL` raises a DB-level exception — proves the NOT NULL guard that exists because Postgres treats NULLs as distinct in a unique index.
- [x] 3.4 GREEN — none beyond 3.1; run 3.3 against real Postgres and confirm it fails without the constraint and passes with it.
- [x] 3.5 RED `tests/Feature/Coaching/SpokenObservationLedgerTest.php`: second claim of the same `(user_id, period_month, subject_key, band)` returns `false`; a caught `UniqueConstraintViolationException` does not poison the enclosing `DB::transaction` (assert a subsequent write in the same outer transaction still succeeds — the 25P02 regression); two concurrent claims of the same band produce exactly one `true` and the loser returns cleanly, no throw; a worse band claims after a better one; a better band is suppressed by `highestBandFor()`; blindness claims once per month; a new `period_month` starts with an empty ladder.
- [x] 3.6 GREEN implement `app/Services/Coaching/SpokenObservationLedger.php` — `claim()` reusing the exact `DB::transaction(fn () => Model::create([...])) / catch (UniqueConstraintViolationException)` pattern from `ChannelUpdateDeduplicator::claim()` (no fourth variant), plus `highestBandFor()` and `confirmSent()`.

## Phase 4: Scheduled sweep (commit 4)

- [x] 4.0 Resolve two interface threads phase 3 left open, consciously rather than by assumption: `SpokenObservationLedger::claim()` takes 11 positional-capable parameters of overlapping types, so callers must use named arguments or the signature should collapse into a DTO; and `highestBandFor()` is subject-scoped while design §5.2's sweep diagram implies a per-subject map, so the sweep either loops per category (≤~20 per user) or gains a bulk sibling. **Decision 1**: collapsed into `App\DTOs\Coaching\ClaimAttempt` — `claim(ClaimAttempt $attempt): bool`. No caller exists yet to "enforce named arguments at the one call site" against (that caller is phase 4.7, not this batch), so a value object was chosen over a documentation-only convention. Phase 3's `SpokenObservationLedgerTest.php` updated to build `ClaimAttempt` instead of spreading named arguments into `claim()`; stayed green throughout (RED confirmed first: TypeError on the old positional signature receiving a `ClaimAttempt`; GREEN after the signature change). **Decision 2**: `highestBandFor()` stays subject-scoped; no bulk sibling added. The future sweep loops per surviving category (≤~20/user per design), each lookup already covered by the unique index's leading columns. Recorded in `SpokenObservationLedger`'s class docblock.
- [x] 4.1 `config/coaching.php` **already exists** — created early by task 2.4/2.5 (Phase 2) because that task's GREEN needed `config('coaching.max_categories')` before this task's original position in the plan. It currently holds only `max_categories=500`. **Extend**, do not create: add `min_day_for_projection=5`, `overrun_margin=0.10`, `lumpy_share=0.50`, `max_observations_per_message=3`, `channels=['telegram']`, `COACHING_ENABLED` kill switch, `COACHING_MAX_OBSERVATIONS`. RED/GREEN via `tests/Feature/Coaching/CoachingConfigTest.php`: defaults, `PaceThresholds` built straight from config, and — the "honoured, not merely present" requirement — `COACHING_MAX_OBSERVATIONS=0` proven to zero out `PaceEvaluator::evaluate()`'s output using already-built phase-1 code. `coaching.enabled` has no consumer yet (`FinancialCoachingService::speak()` is phase 4.7); documented in the config file's own comment as a forward obligation, not claimed as behaviourally enforced this batch.
- [x] 4.2 RED `tests/Feature/Capture/ChannelIdentityResolverReachTest.php`: `preferredIdentityFor()` prefers Telegram over WhatsApp for a dual-identity user (and, triangulated, follows a reversed preference list to WhatsApp on the SAME user — proves order, not a hardcoded rule); `userIdsReachableOn(['telegram'])` excludes a WhatsApp-only user.
- [x] 4.3 GREEN implement `preferredIdentityFor()`, `userIdsReachableOn()` on `app/Services/Capture/ChannelIdentityResolver.php` (chunked via `cursor()` → `LazyCollection`).
- [x] 4.4 RED `tests/Unit/Coaching/CoachingMessageComposerTest.php`: all four band shapes (§6); refund guard omits the share when merchant total exceeds `spent`; singular/plural agreement; **asserts no output ever contains `actual_percentage` or a Pareto bucket share** (D8, non-negotiable) — asserted structurally via `ReflectionClass` over every DTO the composer accepts (`PaceObservation`, `CoachingCause`, `BlindnessObservation`, `CategoryMonthSnapshot`), plus an output-level string guard as a second belt-and-suspenders check.
- [x] 4.5 GREEN implement `app/Services/Coaching/CoachingMessageComposer.php`. No `parse_mode` payload (D10) — assert the outbound payload carries none. New `App\DTOs\Coaching\CoachingCause` value object was introduced (not named explicitly in design.md but required by `PaceObservation::$cause`'s documented-but-untyped placeholder) to carry the merchant/single-transaction cause data the composer needs for both the linear and lumpy shapes.
- [x] 4.5b RED `tests/Unit/Coaching/BlindnessDetectorTest.php`: a category with spend and `monthly_budget = 0` is reported as unwatchable; a category with `monthly_budget = 0` and **no** spend is not reported at all; a budgeted category never appears; only `type = 'expense'` is considered; the report is one observation per user per month keyed `blindness`, never one per category.
- [x] 4.5c GREEN implement blindness detection over the same snapshots the evaluator receives. **It is deliberately NOT in `PaceEvaluator`** — design §5.1 marks `blind` as "not comparable", so it is not a band on the pace ladder and must not be ranked against one. Without this task the owner's explicit decision — that the coach reports what it cannot watch instead of staying silent — has no owner and would be lost between phases. New `App\DTOs\Coaching\BlindnessObservation` value object (`categoryNames: string[]`, `totalSpent: float`) carries the aggregated fact; `App\Services\Coaching\BlindnessDetector::detect()` returns exactly one or `null`, never an array.
- [x] 4.5d RED `tests/Feature/Coaching/CoachingKillSwitchTest.php`: with `coaching.enabled = false`, `speak()` returns `false`, claims nothing and sends nothing — asserted at the service (a mock `CategoryRepositoryContract` with zero expectations proves no snapshot is even queried), not merely that the config key exists. Triangulated with the same over-budget fixture and the switch back at its default (`true`), which claims and sends — proving the disabled case is the switch actually gating something, not an accidentally-always-false method.
- [x] 4.6 RED `tests/Feature/Coaching/FinancialCoachingServiceSweepTest.php`: `claim()` runs **before** `TelegramChannel::reply()` (call-order spy on a mocked `SpokenObservationLedger` plus an `Http::fake()` closure, D7); a failed send (`Http::fake()` non-2xx) does **not** un-claim — the band stays spoken; sweep-vs-capture race across both entry points (sequential, same band) produces exactly one message; `speak()` returns `false` and sends nothing when nothing crosses. **Conflict found and NOT silently resolved**: this task's own wording ("sent_at stays NULL" on a failed send) contradicts design.md §7's failure table, which explicitly states "Telegram sendMessage non-2xx ... sent_at is still set — it only proves the call returned." Since `TelegramChannel::reply()` (explicitly untouched) logs and swallows non-2xx and never throws, `FinancialCoachingService` cannot honestly distinguish a failed send from a successful one without touching the port. Implemented per design.md §7/D7 (the more detailed, explicitly-cited-as-required-reading artifact); the RED test asserts `sent_at` IS set after a failed send, with the conflict documented in the test's own docblock and in the apply return summary.
- [x] 4.7 GREEN implement `app/Services/Coaching/FinancialCoachingService.php::speak()`: snapshot → evaluate → ladder → cause → claim → compose → send → confirm. Also implements `preview(User, CoachingScope): ?string` — a second, narrowly-scoped public method (design.md §1/§2 describe the service as exposing "exactly one"), added because task 4.8's `--dry-run` must compose realistic preview text via the same evaluate/ladder/cause pipeline without claiming or sending, and duplicating that pipeline inside `RunCoachingSweep` would move band/threshold logic into a Command, which both `.agents/rules/01-laravel-core.md` and design.md §1 itself forbid. Flagged as a deviation, not silently added.
- [x] 4.8 RED `tests/Feature/Console/RunCoachingSweepTest.php`: `--dry-run` composes but calls `claim()` **zero** times (asserted structurally: no `coaching_observations` row exists after a dry run over a fixture that would otherwise claim); a normal run claims and sends; the summary line counts total/reachable/no-channel/sent/silent, and a WhatsApp-only user is counted under "sin canal de coaching", never silently dropped.
- [x] 4.9 GREEN implement `app/Console/Commands/RunCoachingSweep.php` (`app:run-coaching-sweep {--dry-run}`). `--dry-run` calls `FinancialCoachingService::preview()` (task 4.7's second method), never `speak()`.
- [x] 4.10 Added `Schedule::command(RunCoachingSweep::class)->dailyAt('20:00')` to `routes/console.php`. `SendSummaryTransactionsByDay`'s `20:08` entry left untouched — verified via `php artisan schedule:list` showing both entries side by side.

## Phase 5: Capture-time check (commit 5)

- [x] 5.1 RED `tests/Feature/Jobs/ProcessTelegramCaptureCoachingTest.php`: a captured expense that crosses the pace line sends exactly one coaching message **after** the ✅ confirmation, evaluated as a counterfactual (with/without the new transaction); a capture that does not cross the line sends confirmation only; an income transaction is never coached; a transaction with `category_id = NULL` is never coached; a coaching exception is caught/logged, the `Transaction` stays registered, the confirmation still sends, and the job is **not** retried. Self-contained file (own `coachingCaptureSender()`/`runCoachingCaptureJob()`/etc. helpers) rather than reusing `ProcessTelegramCaptureTest.php`'s file-local functions, matching the same reasoning already documented on `tests/Support/CaptureFixtures.php`'s own class docblock (a sibling file depending on another file's top-level functions fatals when run alone).
- [x] 5.2 GREEN modified `app/Jobs/ProcessTelegramCapture.php`: guarded `speak(user, CoachingScope::forCategory($categoryId, $amount))` inside `try/catch (Throwable)` after the confirmation reply, extracted to a private `coach()` method. Resolved via `app(FinancialCoachingService::class)` (service locator) rather than a `handle()` method parameter — this deliberately keeps `handle()`'s signature, and therefore every existing direct `->handle(...)` call in `ProcessTelegramCaptureTest.php`, completely untouched. It also mirrors this exact class's own established pattern: `failed()` already resolves `CaptureChannelRegistry` the same way for the same reason (a best-effort side call that must not be forced through the method's normal DI signature).

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
| `sent_at` as a real delivery acknowledgement | Requires a `reply()` port change; deliberately not made here |
| Technical-debt items 1, 2, 10 (nine legacy SQL functions) at large | Proposal non-goal; only D1's bounded exception is touched |
| `CategorizationService` unit tests | Not touched by this change; no scope added |
| Any SPA surface change | Non-goal |
| WhatsApp coaching | Template-gated per ADR-0007; Telegram only |
| Interactive coaching / buttons / reply handling | Shelved with `financial-coaching-clarify` |
