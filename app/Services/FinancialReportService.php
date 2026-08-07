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
     * Each row additionally carries `variance` (`budgeted - spent`) and `status`
     * (`spent > budgeted ? 'Excedido' : 'Dentro'`), computed here at the service
     * boundary rather than in the DB function, so any consumer — the monthly email
     * today — gets a complete row shape without re-deriving it.
     *
     * @return Collection<int, \stdClass>
     */
    public function getBudgetDeviation(int $userId, int $month, int $year): Collection
    {
        // Parameter order in DB function: p_page, p_per_page, p_user_id, p_month, p_year
        $results = DB::select(
            'SELECT id, name, monthly_budget AS budgeted, spent, available_budget, percentage_spent
             FROM get_monthly_category_budget_report(1, 100, ?, ?, ?)',
            [$userId, $month, $year]
        );

        return collect($results)->map(function (\stdClass $row): \stdClass {
            $row->variance = $row->budgeted - $row->spent;
            $row->status = $row->spent > $row->budgeted ? 'Excedido' : 'Dentro';

            return $row;
        });
    }
}
