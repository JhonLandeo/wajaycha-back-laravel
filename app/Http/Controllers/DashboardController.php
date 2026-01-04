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
            $year = $request->input('year');
            $month = $request->input('month');
            $userId = Auth::id();

            $result = DB::select('SELECT get_kpi_data(?, ?, ?) as data', [
                $userId,
                $year,
                $month
            ]);

            $jsonString = $result[0]->data;
            return response()->json(json_decode($jsonString));
        } catch (\Throwable $th) {
            throw $th;
        }
    }

    public function topFiveData(Request $request): JsonResponse
    {
        try {
            $year = $request->input('year');
            $month = $request->input('month');
            $userId = Auth::id();

            $result = DB::select('SELECT get_top_five_data(?, ?, ?) as data', [
                $userId,
                $year,
                $month
            ]);

            return response()->json(json_decode($result[0]->data));
        } catch (\Throwable $th) {
            throw $th;
        }
    }

    public function getWeeklyData(Request $request): JsonResponse
    {
        try {
            $isChecked = $request->boolean('isChecked');
            $year = $request->input('year');
            $month = $request->input('month');
            $userId = Auth::id();

            $result = DB::select('SELECT get_weekly_data(?, ?, ?, ?) as data', [
                $userId,
                $isChecked,
                $year,
                $month
            ]);

            return response()->json(json_decode($result[0]->data));
        } catch (\Throwable $th) {
            throw $th;
        }
    }

    public function getMonthlyData(Request $request): JsonResponse
    {
        try {
            $isChecked = $request->boolean('isChecked');
            $year = $request->input('year');
            $userId = Auth::id();

            $result = DB::select('SELECT get_monthly_data(?, ?, ?) as data', [
                $userId,
                $isChecked,
                $year
            ]);

            return response()->json(json_decode($result[0]->data));
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
