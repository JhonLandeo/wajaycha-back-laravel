<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\DTOs\Pareto\BudgetedCategoryRow;
use App\DTOs\Pareto\ParetoBandRow;
use App\DTOs\Pareto\ParetoWindowTotals;
use App\Models\ParetoClassification;
use Illuminate\Database\Eloquent\Collection;

interface ParetoRepositoryContract
{
    /**
     * Find a ParetoClassification belonging to $userId, or null.
     *
     * $userId is an AUTHORIZATION BOUNDARY, not a convenience filter. Resolving by id
     * alone returns another user's record, which is how every endpoint in this API came
     * to be exploitable. A caller that cannot supply the owner has no business calling
     * this method.
     */
    public function findById(int $id, int $userId): ?ParetoClassification;

    public function getAllForUser(int $userId): Collection;

    /**
     * The user's bands as values, for a builder that must not hold a model.
     *
     * @return array<int, ParetoBandRow>
     */
    public function bandRowsForUser(int $userId): array;

    /**
     * Every LEAF category of the user that is not income, in a band or outside one.
     *
     * Leaves only: a category with children carries no budget of its own, and counting
     * both levels would count the same money twice. Unassigned ones are included on
     * purpose — they weigh in the total that each band's share is taken from, so
     * leaving them out would inflate every band.
     *
     * @return array<int, BudgetedCategoryRow>
     */
    public function budgetedLeafCategories(int $userId): array;

    /**
     * Net movement per category inside a window, expenses positive and income
     * subtracted, exactly as the report reads it.
     *
     * A null month means the whole year; a null year means everything.
     *
     * @return array<int, float>  category id => amount
     */
    public function netSpendByCategory(int $userId, ?int $month, ?int $year): array;

    /**
     * Income and expense across every category in the window.
     */
    public function windowTotals(int $userId, ?int $month, ?int $year): ParetoWindowTotals;

    /**
     * Distinct calendar months the user has movements in.
     *
     * The denominator of last resort: with neither a month nor a year selected, it is
     * the only figure that says how many months of budget the answer covers.
     */
    public function monthsWithActivity(int $userId): int;

    /**
     * Categories assigned to a Pareto classification owned by $userId.
     *
     * $userId is an authorization boundary, not a filter of convenience: without it this
     * returns another user's category names and budgets.
     */
    public function getCategories(int $paretoId, int $userId, ?string $search = null): Collection;

    public function create(array $data): ParetoClassification;

    public function update(ParetoClassification $pareto, array $data): bool;

    public function delete(ParetoClassification $pareto): bool;
}
