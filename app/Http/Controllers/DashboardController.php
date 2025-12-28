<?php

namespace App\Http\Controllers;

use App\Imports\ExpensesImport;
use App\Imports\TransactionYapeImport;
use App\Models\UnifyTransactions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class DashboardController extends Controller
{


    public function kpiData(Request $request): JsonResponse
    {
        try {
            $year = $request->input('year', null);
            $month = $request->input('month', null);
            $userId = Auth::id();
            $avgBase = UnifyTransactions::query()
                ->selectRaw("ROUND(AVG(CASE WHEN type_transaction = 'income' THEN amount ELSE 0 END),2) AS avg_daily_income,
                ROUND(AVG(CASE WHEN type_transaction = 'expense' THEN amount ELSE 0 END),2) AS avg_daily_expense")
                ->join('details as d', 'mv_unified_transactions.detail_id', '=',  'd.id');

            if ($month) {
                $avgBase->whereMonth('date_operation', $month);
            }
            if ($year) {
                $avgBase->whereYear('date_operation', $year);
            }

            $avg = $avgBase->where('mv_unified_transactions.user_id', $userId)
                ->first();

            $balanceBase = UnifyTransactions::query()
                ->selectRaw("SUM(CASE WHEN type_transaction = 'income' THEN amount ELSE 0 END) AS total_income,
            SUM(CASE WHEN type_transaction = 'expense' THEN amount ELSE 0 END) AS total_expense,
            SUM(CASE WHEN type_transaction = 'income' THEN amount ELSE 0 END) 
            - SUM(CASE WHEN type_transaction = 'expense' THEN amount ELSE 0 END) AS balance");

            if ($month) {
                $balance = $balanceBase->whereMonth('date_operation', $month);
            }
            if ($year) {
                $balance = $balanceBase->whereYear('date_operation', $year);
            }

            $balance = $balanceBase->where('mv_unified_transactions.user_id', $userId)
                ->first();


            $data = [
                'avg_daily_income' => ['amount' => (float) $avg->avg_daily_income, 'title' => 'AVG Ingreso diario', 'type' => 'income'],
                'avg_daily_expense' => ['amount' => (float) $avg->avg_daily_expense, 'title' => 'AVG Gasto diario', 'type' => 'expense'],
                'total_income' => ['amount' => (float) $balance->total_income, 'title' => 'Total de ingresos', 'type' => 'income'],
                'total_expense' => ['amount' => (float) $balance->total_expense, 'title' => 'Total de gastos', 'type' => 'expense'],
                'balance' => ['amount' => (float) $balance->balance, 'title' => 'Balance'],
            ];

            return response()->json($data);
        } catch (\Throwable $th) {
            throw $th;
        }
    }

    public function topFiveData(Request $request): JsonResponse
    {
        try {
            $year = $request->input('year', null);
            $month = $request->input('month', null);
            $userId = Auth::id();
            $queryBase = UnifyTransactions::query()
                ->selectRaw("CAST(SUM(amount) AS DECIMAL(10,2)) as value, detail_name as name")
                ->where('user_id', $userId);

            $queryIncomes = clone $queryBase;
            $queryExpenses = clone $queryBase;
            if ($month) {
                $queryIncomes->whereMonth('date_operation', $month);
                $queryExpenses->whereMonth('date_operation', $month);
            }
            if ($year) {
                $queryIncomes->whereYear('date_operation', $year);
                $queryExpenses->whereYear('date_operation', $year);
            }
            $topIncomes = $queryIncomes
                ->where('type_transaction', 'income')
                ->groupBy('detail_name')
                ->orderBy('value', 'desc')
                ->limit(5)
                ->get()
                ->map(function ($item) {
                    /** @var \App\Models\UnifyTransactions $item */
                    $item->value = (float) $item->value;
                    return $item;
                });

            $topExpenses = $queryExpenses
                ->where('type_transaction', 'expense')
                ->groupBy('detail_name')
                ->orderBy('value', 'desc')
                ->limit(5)
                ->get()
                ->map(function ($item) {
                    /** @var \App\Models\UnifyTransactions $item */
                    $item->value = (float) $item->value;
                    return $item;
                });


            $data = [
                'top_five_expenses' => $topExpenses,
                'top_five_incomes' => $topIncomes,
            ];

            return response()->json($data);
        } catch (\Throwable $th) {
            throw $th;
        }
    }

    public function getWeeklyData(Request $request): JsonResponse
    {
        try {
            $isChecked = $request->input('isChecked', false);
            $userId = Auth::id();

            $selectAggregate = $isChecked ?
                "COUNT(CASE WHEN type_transaction = 'income' THEN t.id END) AS count_weekly_income,
             COUNT(CASE WHEN type_transaction = 'expense' THEN t.id END) AS count_weekly_expense" :
                "ROUND(SUM(CASE WHEN type_transaction = 'income' THEN amount ELSE 0 END),2) AS sum_weekly_income,
             ROUND(SUM(CASE WHEN type_transaction = 'expense' THEN amount ELSE 0 END),2) AS sum_weekly_expense";

            $dayOfWeekExpression = "EXTRACT(ISODOW FROM t.date_operation)";
            $dayNameExpression = "TRIM(to_char(t.date_operation, 'Day'))";
            $query = UnifyTransactions::query()
                ->from('mv_unified_transactions as t')
                ->join('details as d', 't.detail_id', '=', 'd.id')
                ->selectRaw("
                {$selectAggregate},
                {$dayOfWeekExpression} AS day,
                {$dayNameExpression} AS name_day
            ");

            if ($request->filled('year')) {
                $query->whereYear('t.date_operation', $request->year);
            }
            if ($request->filled('month')) {
                $query->whereMonth('t.date_operation', $request->month);
            }

            $results = $query
                ->where('t.user_id', $userId)
                ->groupByRaw("{$dayOfWeekExpression}, {$dayNameExpression}")
                ->orderByRaw($dayOfWeekExpression)
                ->get();

            foreach ($results as $result) {
                $result->name_day = mb_convert_case($result->name_day, MB_CASE_TITLE, "UTF-8");
            }

            return response()->json($results);
        } catch (\Throwable $th) {
            throw $th;
        }
    }

    public function getHourlyData(Request $request): JsonResponse
    {
        try {
            $userId = Auth::id();
            $data = UnifyTransactions::selectRaw("
            ROUND(AVG(CASE WHEN type_transaction = 'income' THEN amount ELSE 0 END), 2) AS avg_daily_income,
            ROUND(AVG(CASE WHEN type_transaction = 'expense' THEN amount ELSE 0 END), 2) AS avg_daily_expense,
            EXTRACT(HOUR FROM date_operation) AS hour  -- CAMBIO 1: Se usa EXTRACT en lugar de HOUR()
        ")
                ->join('details as d', 'mv_unified_transactions.detail_id', '=', 'd.id')
                ->where('mv_unified_transactions.user_id', $userId)
                ->whereYear('date_operation', $request->year)
                ->groupByRaw('EXTRACT(HOUR FROM date_operation)')
                ->orderBy('hour')
                ->get();

            return response()->json($data);
        } catch (\Throwable $th) {
            throw $th;
        }
    }

    public function getMonthlyData(Request $request): JsonResponse
    {
        try {
            $isChecked = $request->input('isChecked', false);
            $userId = Auth::id();
            $selectAggregate = $isChecked ?
                "COUNT(CASE WHEN type_transaction = 'income' THEN t.id END) AS count_monthly_income,
             COUNT(CASE WHEN type_transaction = 'expense' THEN t.id END) AS count_monthly_expense" :
                "ROUND(SUM(CASE WHEN type_transaction = 'income' THEN amount ELSE 0 END),2) AS sum_monthly_income,
             ROUND(SUM(CASE WHEN type_transaction = 'expense' THEN amount ELSE 0 END),2) AS sum_monthly_expense";

            $monthNumberExpression = "EXTRACT(MONTH FROM t.date_operation)";

            $baseQuery = UnifyTransactions::query()
                ->from('mv_unified_transactions as t')
                ->join('details as d', 't.detail_id', '=',  'd.id')
                ->selectRaw("
                {$selectAggregate},
                {$monthNumberExpression} AS month")
                ->where('t.user_id', $userId);

            if ($request->filled('year')) {
                $baseQuery->whereYear('t.date_operation', $request->year);
            }

            $data = $baseQuery
                ->groupByRaw($monthNumberExpression)
                ->orderByRaw($monthNumberExpression)
                ->get();


            $monthMap = [
                1 => 'Enero',
                2 => 'Febrero',
                3 => 'Marzo',
                4 => 'Abril',
                5 => 'Mayo',
                6 => 'Junio',
                7 => 'Julio',
                8 => 'Agosto',
                9 => 'Septiembre',
                10 => 'Octubre',
                11 => 'Noviembre',
                12 => 'Diciembre',
            ];

            $formattedData = $data->map(function ($item) use ($monthMap) {
                $item->name_month = $monthMap[$item->month] ?? 'Desconocido';
                return $item;
            });

            return response()->json($formattedData);
        } catch (\Throwable $th) {
            throw $th;
        }
    }

    public function getTransactionByCategory(Request $request): JsonResponse
    {
        $year = $request->input('year', null);
        $month = $request->input('month', null);
        $userId = Auth::id();

        $nameExpression = "COALESCE(c.name, 'Sin categorizar')";
        $totalExpression = "SUM(CASE 
        WHEN t.type_transaction = 'expense' THEN t.amount 
        WHEN t.type_transaction = 'income' THEN -t.amount 
        ELSE 0 
        END)";

        $query = UnifyTransactions::query()
            ->from('mv_unified_transactions as t')
            ->leftJoin('details as d', 'd.id', '=', 't.detail_id')
            ->leftJoin('categories as c', 'c.id', '=', 't.category_id')
            ->select(
                DB::raw("{$nameExpression} as name"),
                DB::raw('COUNT(*) as quantity'),
                DB::raw("{$totalExpression} as total")
            );

        if ($year) {
            $query->whereYear('t.date_operation', $year);
        }

        if ($month) {
            $query->whereMonth('t.date_operation', $month);
        }

        if ($userId) {
            $query->where('t.user_id', $userId);
        }

        $results = $query
            ->groupByRaw($nameExpression)
            ->havingRaw("{$totalExpression} > 0")
            ->orderByRaw("{$totalExpression} DESC")
            ->get();

        return response()->json($results);
    }
}
