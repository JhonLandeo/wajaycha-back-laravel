<?php

namespace App\Http\Controllers;

use App\Imports\ExpensesImport;
use App\Imports\TransactionYapeImport;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class DashboardController extends Controller
{
    

    public function kpiData(Request $request)
    {
        try {
            $avg = Transaction::selectRaw("ROUND(AVG(CASE WHEN type_transaction = 'income' THEN amount ELSE 0 END),2) AS avg_daily_income,
            ROUND(AVG(CASE WHEN type_transaction = 'expense' THEN amount ELSE 0 END),2) AS avg_daily_expense")
                ->whereYear('date_operation', $request->year)
                ->first();

            $balance = Transaction::selectRaw("SUM(CASE WHEN type_transaction = 'income' THEN amount ELSE 0 END) AS total_income,
            SUM(CASE WHEN type_transaction = 'expense' THEN amount ELSE 0 END) AS total_expense,
            SUM(CASE WHEN type_transaction = 'income' THEN amount ELSE 0 END) 
            - SUM(CASE WHEN type_transaction = 'expense' THEN amount ELSE 0 END) AS balance")
                ->whereYear('date_operation', $request->year)
                ->first();

            $data = [
                'avg_daily_income' => ['amount' => (float) $avg->avg_daily_income, 'title' => 'AVG Daily Income', 'type' => 'income'],
                'avg_daily_expense' => ['amount' => (float) $avg->avg_daily_expense, 'title' => 'AVG Daily Expense', 'type' => 'expense'],
                'total_income' => ['amount' => (float) $balance->total_income, 'title' => 'Total Income', 'type' => 'income'],
                'total_expense' => ['amount' => (float) $balance->total_expense, 'title' => 'Total Transaction', 'type' => 'expense'],
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
                ->whereYear('date_operation', $request->year)
                ->groupBy('d.name')
                ->orderBy('value', 'desc')
                ->limit(5)
                ->get();


            $topIncomes = Transaction::selectRaw("SUM(amount) value, d.name name")
                ->join('details as d', 'transactions.detail_id', '=',  'd.id')
                ->where('type_transaction', '=', 'income')
                ->whereYear('date_operation', $request->year)
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
                ->whereYear('date_operation', $request->year)
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
}
