<?php

namespace App\Http\Controllers;

use App\Imports\ExpensesImport;
use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class ExpensesController extends Controller
{
    //

    public function import(Request $request)
    {
        try {
            $file = $request->file('file');
            Excel::import(new ExpensesImport(), $file);
            return response()->json(['status' => 'ok']);
        } catch (\Throwable $th) {
            throw $th;
        }
    }

    public function kpiData()
    {
        try {
            $avg = Expense::selectRaw("ROUND(AVG(CASE WHEN type_transaction = 'income' THEN amount ELSE 0 END),2) AS avg_daily_income,
            ROUND(AVG(CASE WHEN type_transaction = 'expense' THEN amount ELSE 0 END),2) AS avg_daily_expense")
                ->first();

            $balance = Expense::selectRaw("SUM(CASE WHEN type_transaction = 'income' THEN amount ELSE 0 END) AS total_income,
            SUM(CASE WHEN type_transaction = 'expense' THEN amount ELSE 0 END) AS total_expense,
            SUM(CASE WHEN type_transaction = 'income' THEN amount ELSE 0 END) 
            - SUM(CASE WHEN type_transaction = 'expense' THEN amount ELSE 0 END) AS balance")
                ->first();

            $data = [
                'avg_daily_income' => ['amount' => (float) $avg->avg_daily_income, 'title' => 'AVG Daily Income', 'type' => 'income'],
                'avg_daily_expense' => ['amount' => (float) $avg->avg_daily_expense, 'title' => 'AVG Daily Expense', 'type' => 'expense'],
                'total_income' => ['amount' => (float) $balance->total_income, 'title' => 'Total Income', 'type' => 'income'],
                'total_expense' => ['amount' => (float) $balance->total_expense, 'title' => 'Total Expense', 'type' => 'expense'],
                'balance' => ['amount' => (float) $balance->balance, 'title' => 'Balance'],
            ];

            return response()->json($data);
        } catch (\Throwable $th) {
            throw $th;
        }
    }

    public function topFiveData()
    {
        try {
            $topExpenses = Expense::selectRaw("SUM(amount) value, destination name")
                ->where('type_transaction', '=', 'expense')
                ->groupBy('destination')
                ->orderBy('value', 'desc')
                ->limit(5)
                ->get();

            $topIncomes = Expense::selectRaw("SUM(amount) value, origin name")
                ->where('type_transaction', '=', 'income')
                ->groupBy('origin')
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

    public function getWeeklyData(){
        try {
            $data = Expense::selectRaw(" ROUND(AVG(CASE WHEN type_transaction = 'income' THEN amount ELSE 0 END),2) AS avg_daily_income,
            ROUND(AVG(CASE WHEN type_transaction = 'expense' THEN amount ELSE 0 END),2) AS avg_daily_expense,
            DAYOFWEEK(date_operation) day,
            DAYNAME(date_operation) name_day")
            ->groupBy('day')
            ->groupBy('name_day')
            ->orderBy('day')
            ->get();
            return response()->json($data);
        } catch (\Throwable $th) {
            throw $th;
        }

    }

    public function getHourlyData(){
        try {
            $data = Expense::selectRaw("ROUND(AVG(CASE WHEN type_transaction = 'income' THEN amount ELSE 0 END),2) AS avg_daily_income,
             ROUND(AVG(CASE WHEN type_transaction = 'expense' THEN amount ELSE 0 END),2) AS avg_daily_expense,
            HOUR(date_operation) hour")
            ->groupBY('hour')
            ->orderBy('hour')
            ->get();

            return response()->json($data);
        } catch (\Throwable $th) {
            throw $th;
        }
        
    }

    public function getMonthlyData(){
        try {
            $data = Expense::selectRaw("ROUND(AVG(CASE WHEN type_transaction = 'income' THEN amount ELSE 0 END),2) AS avg_monthly_income,
             ROUND(AVG(CASE WHEN type_transaction = 'expense' THEN amount ELSE 0 END),2) AS avg_monthly_expense,
            MONTH(date_operation) month,
            MONTHNAME(date_operation) name_month")
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
