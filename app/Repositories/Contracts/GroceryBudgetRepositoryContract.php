<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\DTOs\Shopping\GroceryCategoryMatch;
use App\DTOs\Shopping\GroceryCeilingInput;
use App\Models\GroceryBudgetLink;
use Carbon\CarbonImmutable;

/**
 * Owns `grocery_budget_links` and composes `CategoryRepositoryContract` and
 * `TransactionRepositoryContract` for the ceiling read (design.md D2). No
 * new method lands on either foreign contract — `expenseByCategoryBetween()`
 * already exists (F2) and this contract's own exact-name lookup is answered
 * by filtering `CategoryRepositoryContract::getAllForUser()` in PHP.
 */
interface GroceryBudgetRepositoryContract
{
    /**
     * $userId is an AUTHORIZATION BOUNDARY, not a convenience filter
     * (design.md D8) — a caller that cannot supply the owner has no
     * business calling this method. One pin per user (`unq_grocery_budget_links_user_id`),
     * so there is at most one row to find.
     */
    public function findLink(int $userId): ?GroceryBudgetLink;

    /**
     * Exact string equality against the caller's own Categories, never
     * `ILIKE` — a near-match is the user's own rename and must not be
     * guessed (design.md D2). Filters
     * `CategoryRepositoryContract::getAllForUser($userId)` in PHP rather
     * than adding a new query method to that contract.
     */
    public function findCategoryByExactName(int $userId, string $name): ?GroceryCategoryMatch;

    /**
     * Writes (or replaces) the one pin a user may hold, upserting on
     * `user_id` — the same statement serves both call sites:
     *
     * - The lazy-resolve path (design.md D6, `resolvedBy: 'auto'`) calls
     *   this only after confirming no link exists, so two concurrent
     *   callers both attempt the write; `unq_grocery_budget_links_user_id`
     *   is the arbiter (PostgreSQL's own `ON CONFLICT ... DO UPDATE`, not
     *   application logic), and since both had resolved the same name the
     *   race has no observable outcome.
     * - The manual override (design.md D6, `resolvedBy: 'manual'`) calls
     *   this to genuinely replace whichever category was pinned before.
     */
    public function pin(int $userId, int $categoryId, string $resolvedBy): GroceryBudgetLink;

    /**
     * The composed spend/ceiling read (design.md D2, D5). Returns null
     * when there is no link, or when the link's Category no longer
     * resolves for this user — both collapse to the `unlinked` state; the
     * caller decides `unbudgeted` vs `set` from `$monthlyBudget`'s sign.
     *
     * The date window is derived from `$asOf` and the Category's own
     * `BudgetPeriod` (design.md D5's window table) — never exposed on the
     * returned DTO, since nothing downstream needs it.
     *
     * Deviation from tasks.md 4.9's literal `PlanningWeek $week` signature:
     * `PlanningWeek` is slice 5's DTO (tasks.md 5.1-5.2) and slice 4 is
     * scoped to depend on slice 1 only. `$asOf` is the only field of
     * `PlanningWeek` this read actually needs — see design.md's window
     * table, which keys off the reference date, not the week's Monday/
     * Sunday bounds. `CarbonImmutable` keeps this method callable from
     * slice 5's `BuildWeeklyPlanAction` with `$week->asOf` once
     * `PlanningWeek` exists.
     */
    public function ceilingFor(int $userId, CarbonImmutable $asOf): ?GroceryCeilingInput;
}
