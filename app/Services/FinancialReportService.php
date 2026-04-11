<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class FinancialReportService
{
    /**
     * Calcula la desviación presupuestaria por categoría para un mes/año específico.
     */
    public function getBudgetDeviation(int $userId, int $month, int $year)
    {
        // Esta consulta compara lo que presupuestaste (en tu tabla de categorías)
        // contra lo que realmente gastaste (en tu vista unificada).
        return DB::table('categories as c')
            ->select([
                'c.name as category',
                'c.monthly_budget as budgeted',
                DB::raw('COALESCE(SUM(v.amount), 0) as real'),
                DB::raw('c.monthly_budget - COALESCE(SUM(v.amount), 0) as variance'),
                DB::raw("CASE 
                    WHEN COALESCE(SUM(v.amount), 0) > c.monthly_budget THEN 'Excedido' 
                    ELSE 'Ahorro' 
                END as status")
            ])
            ->leftJoin('v_unified_transactions as v', function ($join) use ($month, $year) {
                $join->on('v.category_id', '=', 'c.id')
                    ->whereMonth('v.date_operation', $month)
                    ->whereYear('v.date_operation', $year)
                    ->where('v.type_transaction', 'expense');
            })
            ->where('c.user_id', $userId)
            ->groupBy('c.id', 'c.name', 'c.monthly_budget')
            ->get();
    }
}
