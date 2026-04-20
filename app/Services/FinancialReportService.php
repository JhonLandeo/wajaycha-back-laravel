<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FinancialReportService
{
    /**
     * Get budget deviation for a user in a specific month and year.
     *
     * @param int $userId
     * @param int $month
     * @param int $year
     * @return Collection<int, \stdClass>
     */
    public function getBudgetDeviation(int $userId, int $month, int $year): Collection
    {
        // Parameter order in DB function: p_page, p_per_page, p_user_id, p_month, p_year
        $results = DB::select(
            "SELECT id, name, monthly_budget AS budgeted, spent, available_budget, percentage_spent 
             FROM get_monthly_category_budget_report(1, 100, ?, ?, ?)",
            [$userId, $month, $year]
        );

        return collect($results);
    }
}
