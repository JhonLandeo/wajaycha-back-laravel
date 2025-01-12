<?php

namespace App\Http\Controllers;

use App\Imports\ExpensesImport;
use App\Imports\TransactionYapeImport;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class DashboardController extends Controller
{


    public function kpiData(Request $request)
    {
        try {
            $avg = Transaction::selectRaw("ROUND(AVG(CASE WHEN type_transaction = 'income' THEN amount ELSE 0 END),2) AS avg_daily_income,
            ROUND(AVG(CASE WHEN type_transaction = 'expense' THEN amount ELSE 0 END),2) AS avg_daily_expense")
                ->join('details as d', 'transactions.detail_id', '=',  'd.id')
                ->whereYear('date_operation', $request->year)
                ->where('d.user_id', $request->user_id)
                ->first();

            $balance = Transaction::selectRaw("SUM(CASE WHEN type_transaction = 'income' THEN amount ELSE 0 END) AS total_income,
            SUM(CASE WHEN type_transaction = 'expense' THEN amount ELSE 0 END) AS total_expense,
            SUM(CASE WHEN type_transaction = 'income' THEN amount ELSE 0 END) 
            - SUM(CASE WHEN type_transaction = 'expense' THEN amount ELSE 0 END) AS balance")
                ->join('details as d', 'transactions.detail_id', '=',  'd.id')
                ->whereYear('date_operation', $request->year)
                ->where('d.user_id', $request->user_id)
                ->first();

            $data = [
                'avg_daily_income' => ['amount' => (float) $avg->avg_daily_income, 'title' => 'AVG Daily Income', 'type' => 'income'],
                'avg_daily_expense' => ['amount' => (float) $avg->avg_daily_expense, 'title' => 'AVG Daily Expense', 'type' => 'expense'],
                'total_income' => ['amount' => (float) $balance->total_income, 'title' => 'Total de ingresos', 'type' => 'income'],
                'total_expense' => ['amount' => (float) $balance->total_expense, 'title' => 'Total de gastos', 'type' => 'expense'],
                'balance' => ['amount' => (float) $balance->balance, 'title' => 'Balance'],
            ];

            return response()->json($data);
        } catch (\Throwable $th) {
            throw $th;
        }
    }

    public function topFiveData(Request $request)
    {
        try {
            $topExpenses = Transaction::selectRaw("SUM(amount) value, d.name name")
                ->join('details as d', 'transactions.detail_id', '=',  'd.id')
                ->where('type_transaction', '=', 'expense')
                ->where('d.user_id', $request->user_id)
                ->whereYear('date_operation', $request->year)
                ->whereMonth('date_operation', $request->month)
                ->groupBy('d.name')
                ->orderBy('value', 'desc')
                ->limit(5)
                ->get();


            $topIncomes = Transaction::selectRaw("SUM(amount) value, d.name name")
                ->join('details as d', 'transactions.detail_id', '=',  'd.id')
                ->where('type_transaction', '=', 'income')
                ->where('d.user_id', $request->user_id)
                ->whereYear('date_operation', $request->year)
                ->whereMonth('date_operation', $request->month)
                ->groupBy('d.name')
                ->orderBy('value', 'desc')
                ->limit(5)
                ->get();

            $data = [
                'top_five_expenses' => $topExpenses,
                'top_five_incomes' => $topIncomes,
            ];

            return response()->json($data);
        } catch (\Throwable $th) {
            throw $th;
        }
    }

    public function getWeeklyData(Request $request)
    {
        try {
            $data = Transaction::selectRaw(" ROUND(AVG(CASE WHEN type_transaction = 'income' THEN amount ELSE 0 END),2) AS avg_daily_income,
            ROUND(AVG(CASE WHEN type_transaction = 'expense' THEN amount ELSE 0 END),2) AS avg_daily_expense,
            DAYOFWEEK(date_operation) day,
            DAYNAME(date_operation) name_day")
                ->join('details as d', 'transactions.detail_id', '=',  'd.id')
                ->where('d.user_id', $request->user_id)
                ->whereYear('date_operation', $request->year)
                ->whereMonth('date_operation', $request->month)
                ->groupBy('day')
                ->groupBy('name_day')
                ->orderBy('day')
                ->get();
            return response()->json($data);
        } catch (\Throwable $th) {
            throw $th;
        }
    }

    public function getHourlyData(Request $request)
    {
        try {
            $data = Transaction::selectRaw("ROUND(AVG(CASE WHEN type_transaction = 'income' THEN amount ELSE 0 END),2) AS avg_daily_income,
             ROUND(AVG(CASE WHEN type_transaction = 'expense' THEN amount ELSE 0 END),2) AS avg_daily_expense,
            HOUR(date_operation) hour")
                ->join('details as d', 'transactions.detail_id', '=',  'd.id')
                ->where('d.user_id', $request->user_id)
                ->whereYear('date_operation', $request->year)
                ->groupBY('hour')
                ->orderBy('hour')
                ->get();

            return response()->json($data);
        } catch (\Throwable $th) {
            throw $th;
        }
    }

    public function getMonthlyData(Request $request)
    {
        try {
            $data = Transaction::selectRaw("ROUND(AVG(CASE WHEN type_transaction = 'income' THEN amount ELSE 0 END),2) AS avg_monthly_income,
             ROUND(AVG(CASE WHEN type_transaction = 'expense' THEN amount ELSE 0 END),2) AS avg_monthly_expense,
            MONTH(date_operation) month,
            MONTHNAME(date_operation) name_month")
                ->join('details as d', 'transactions.detail_id', '=',  'd.id')
                ->where('d.user_id', $request->user_id)
                ->whereYear('date_operation', $request->year)
                ->groupBy('month')
                ->groupBy('name_month')
                ->orderBy('month')
                ->get();
            return response()->json($data);
        } catch (\Throwable $th) {
            throw $th;
        }
    }

    public function getTransactionBySubcategory(Request $request)
    {
        $year = $request->input('year', null);
        $month = $request->input('month', null);
        $type = $request->input('type', null);
        $userId = $request->input('user_id', null);

        $query = Transaction::leftJoin('details as d', 'd.id', '=', 'transactions.detail_id')
            ->leftJoin('sub_categories as sc', 'sc.id', '=', 'transactions.sub_category_id')
            ->select(
                DB::raw('COALESCE(sc.name, "Sin categorizar") as name'),
                DB::raw('COUNT(*) as quantity'),
                DB::raw('SUM(transactions.amount) as total')
            )
            ->where('transactions.type_transaction', 'expense');

        if ($year) {
            $query->whereYear('transactions.date_operation', $year);
        }

        if ($month) {
            $query->whereMonth('transactions.date_operation', $month);
        }

        // if ($type) {
        //     $query->where('transactions.type_transaction', $type);
        // }

        if ($userId) {
            $query->where('d.user_id', $userId);
        }

        $results = $query->groupBy('sc.name')
            ->orderBy(DB::raw('SUM(transactions.amount)'), 'desc')
            ->get();



        return response()->json($results);
    }
}
