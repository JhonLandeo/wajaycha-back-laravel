<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\Contracts\CategoryRepositoryContract;
use Illuminate\Support\Collection;

class FinancialReportService
{
    public function __construct(
        private readonly CategoryRepositoryContract $categories,
    ) {}

    /**
     * Get budget deviation for a user in a specific month and year.
     *
     * The split here is the one ADR-0009 asks for. The repository returns figures;
     * this decides what they mean. `variance` and `status` are a verdict — over budget
     * or within it — and a stored procedure can return 82% without being able to say
     * whether 82% is a problem. That judgement is domain work and stays in PHP, which
     * is also what makes it testable against a fixed set of rows.
     *
     * @return Collection<int, \stdClass>
     */
    public function getBudgetDeviation(int $userId, int $month, int $year): Collection
    {
        $rows = $this->categories->budgetDeviationRowsForMonth($userId, $month, $year);

        return collect($rows)->map(function (\stdClass $row): \stdClass {
            $row->variance = $row->budgeted - $row->spent;
            $row->status = $row->spent > $row->budgeted ? 'Excedido' : 'Dentro';

            return $row;
        });
    }
}
