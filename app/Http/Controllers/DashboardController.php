<?php

namespace App\Http\Controllers;

use App\Imports\ExpensesImport;
use App\Imports\TransactionYapeImport;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class DashboardController extends Controller
{


    public function kpiData(Request $request): JsonResponse
    {
        try {
            $avg = DB::table('transactions')
                ->selectRaw("ROUND(AVG(CASE WHEN type_transaction = 'income' THEN amount ELSE 0 END),2) AS avg_daily_income,
            ROUND(AVG(CASE WHEN type_transaction = 'expense' THEN amount ELSE 0 END),2) AS avg_daily_expense")
                ->join('details as d', 'transactions.detail_id', '=',  'd.id')
                ->whereYear('date_operation', $request->year)
                ->whereYear('date_operation', $request->year)
                ->where('transactions.user_id', $request->user_id)
                ->first();

            $balance = DB::table('transactions')
                ->selectRaw("SUM(CASE WHEN type_transaction = 'income' THEN amount ELSE 0 END) AS total_income,
            SUM(CASE WHEN type_transaction = 'expense' THEN amount ELSE 0 END) AS total_expense,
            SUM(CASE WHEN type_transaction = 'income' THEN amount ELSE 0 END) 
            - SUM(CASE WHEN type_transaction = 'expense' THEN amount ELSE 0 END) AS balance")
                ->join('details as d', 'transactions.detail_id', '=',  'd.id')
                ->whereYear('date_operation', $request->year)
                ->whereYear('date_operation', $request->year)
                ->where('transactions.user_id', $request->user_id)
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
            $queryBase = DB::table('transactions as t')
                ->selectRaw("CAST(SUM(amount) AS DECIMAL(10,2)) as value, d.name as name")
                ->join('details as d', 't.detail_id', '=',  'd.id')
                ->where('t.user_id', $request->user_id);

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
                ->groupBy('d.name')
                ->orderBy('value', 'desc')
                ->limit(5)
                ->get()
                ->map(function ($item) {
                    $item->value = (float) $item->value;
                    return $item;
                });

            $topExpenses = $queryExpenses
                ->where('type_transaction', 'expense')
                ->groupBy('d.name')
                ->orderBy('value', 'desc')
                ->limit(5)
                ->get()
                ->map(function ($item) {
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
            DB::statement("SET lc_time_names = 'es_ES'");

            $isChecked = $request->input('isChecked', false);
            $selectValidate = $isChecked ?
                "ROUND(COUNT(CASE 
                WHEN type_transaction = 'income' THEN t.id END),2) AS count_weekly_income,
             ROUND(COUNT(CASE 
                WHEN type_transaction = 'expense' THEN t.id END),2) AS count_weekly_expense" :
                "ROUND(SUM(CASE 
                WHEN type_transaction = 'income' THEN amount ELSE 0 END),2) AS sum_weekly_income,
             ROUND(SUM(CASE 
                WHEN type_transaction = 'expense' THEN amount ELSE 0 END),2) AS sum_weekly_expense";    

            $year = $request->input('year', null);
            $month = $request->input('month', null);
            $query = DB::table('transactions as t')
                ->selectRaw("$selectValidate,
            DAYOFWEEK(date_operation) day,
            DAYNAME(date_operation) name_day")
                ->join('details as d', 't.detail_id', '=',  'd.id');
            if ($year) {
                $query->whereYear('date_operation', $year);
            }
            if ($month) {
                $query->whereMonth('date_operation', $month);
            }
            $results =
                $query
                ->where('t.user_id', $request->user_id)
                ->groupBy('day')
                ->groupBy('name_day')
                ->orderBy('day')
                ->get();
            foreach ($results as $result) {
                $result->name_day = ucfirst($result->name_day);
            }
            return response()->json($results);
        } catch (\Throwable $th) {
            throw $th;
        }
    }

    public function getHourlyData(Request $request): JsonResponse
    {
        try {
            $data = Transaction::selectRaw("ROUND(AVG(CASE WHEN type_transaction = 'income' THEN amount ELSE 0 END),2) AS avg_daily_income,
             ROUND(AVG(CASE WHEN type_transaction = 'expense' THEN amount ELSE 0 END),2) AS avg_daily_expense,
            HOUR(date_operation) hour")
                ->join('details as d', 'transactions.detail_id', '=',  'd.id')
                ->where('transactions.user_id', $request->user_id)
                ->whereYear('date_operation', $request->year)
                ->groupBY('hour')
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
            DB::statement("SET lc_time_names = 'es_ES'");

            $selectValidate = $request->isChecked ?
                "ROUND(COUNT(CASE 
                WHEN type_transaction = 'income' THEN t.id END),2) AS count_monthly_income,
             ROUND(COUNT(CASE 
                WHEN type_transaction = 'expense' THEN t.id END),2) AS count_monthly_expense" :
                "ROUND(SUM(CASE 
                WHEN type_transaction = 'income' THEN amount ELSE 0 END),2) AS sum_monthly_income,
             ROUND(SUM(CASE 
                WHEN type_transaction = 'expense' THEN amount ELSE 0 END),2) AS sum_monthly_expense";


            $data = DB::table('transactions as t')
                ->selectRaw("$selectValidate,
            MONTH(date_operation) month,
            MONTHNAME(date_operation) name_month")
                ->join('details as d', 't.detail_id', '=',  'd.id')
                ->where('t.user_id', $request->user_id)
                ->whereYear('date_operation', $request->year)
                ->groupBy('month')
                ->groupBy('name_month')
                ->orderBy('month')
                ->get();
            foreach ($data as $item) {
                $item->name_month = ucfirst($item->name_month);
            }
            return response()->json($data);
        } catch (\Throwable $th) {
            throw $th;
        }
    }

    public function getTransactionBySubcategory(Request $request): JsonResponse
    {
        $year = $request->input('year', null);
        $month = $request->input('month', null);
        $type = $request->input('type', null);
        $userId = $request->input('user_id', null);

        $query = DB::table('transactions as t')
            ->leftJoin('details as d', 'd.id', '=', 't.detail_id')
            ->leftJoin('sub_categories as sc', 'sc.id', '=', 't.sub_category_id')
            ->select(
                DB::raw('COALESCE(sc.name, "Sin categorizar") as name'),
                DB::raw('COUNT(*) as quantity'),
                DB::raw(" SUM(CASE 
                    WHEN t.type_transaction = 'expense' THEN t.amount 
                    WHEN t.type_transaction = 'income' THEN -t.amount 
                    ELSE 0 
                END) as total")
            );

        if ($year) {
            $query->whereYear('t.date_operation', $year);
        }

        if ($month) {
            $query->whereMonth('t.date_operation', $month);
        }

        // if ($type) {
        //     $query->where('t.type_transaction', $type);
        // }

        if ($userId) {
            $query->where('t.user_id', $userId);
        }

        $results = $query->groupBy('sc.name')
            ->orderBy(DB::raw('total'), 'desc')
            ->get();

        $filter = $results->filter(function ($item) {
            return $item->total > 0;
        });



        return response()->json($filter);
    }
}
