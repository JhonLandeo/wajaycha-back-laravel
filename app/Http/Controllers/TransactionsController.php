<?php

namespace App\Http\Controllers;

use App\Exports\TransactionsExport;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Js;
use Maatwebsite\Excel\Facades\Excel;

class TransactionsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 10);
        $page = $request->input('page', 1);
        $year = $request->input('year', null);
        $month = $request->input('month', null);
        $type = $request->input('type', null);
        $amount = $request->input('amount', null);
        $search = $request->input('search', null);
        $subCategory = $request->input('sub_category', null);
        $userId = $request->input('user_id', null);
        $recurring = filter_var($request->input('recurring', false), FILTER_VALIDATE_BOOLEAN);
        $weekend = filter_var($request->input('weekend', false), FILTER_VALIDATE_BOOLEAN);
        $workday = filter_var($request->input('workday', false), FILTER_VALIDATE_BOOLEAN);


        $subQuery = "
            (SELECT JSON_ARRAYAGG(JSON_OBJECT(
                'date_operation', yape_trans.date_operation,
                'amount', yape_trans.amount,
                'origin', yape_trans.origin,
                'destination', yape_trans.destination,
                'type_transaction', yape_trans.type_transaction,
                'message', yape_trans.message
            ))
            FROM transaction_yapes yape_trans
            WHERE yape_trans.amount = transactions.amount
              AND yape_trans.user_id = $userId
              AND DATE(yape_trans.date_operation) = DATE(transactions.date_operation)
            ) AS relation
        ";

        $subQueryFrequency = "(
            SELECT
                COUNT(*)
            FROM
                transactions t2
            WHERE
                t2.detail_id = transactions.detail_id
            GROUP BY
                t2.detail_id ) as frequency";

        $query = Transaction::with('detail')
            ->join('details as d', 'd.id', '=', 'transactions.detail_id')
            ->selectRaw("transactions.*, $subQuery")
            ->selectRaw("transactions.*, $subQueryFrequency");

        if ($year) {
            $query->whereYear('date_operation', $year);
        }

        if ($month) {
            $query->whereMonth('date_operation', $month);
        }

        if ($weekend) {
            $query->whereIn(DB::raw('DAYOFWEEK(date_operation)'), [1, 7]);
        }

        if ($workday) {
            $query->whereBetween(DB::raw('DAYOFWEEK(date_operation)'), [2, 6]);
        }


        if ($type) {
            $query->where('type_transaction', $type);
        }
        if ($amount && $amount != 0.00) {
            $query->where('amount', $amount);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->whereLike('d.name', "%$search%")
                    ->orWhereRaw("EXISTS (
                      SELECT 1
                      FROM transaction_yapes yape_trans
                      WHERE yape_trans.amount = transactions.amount
                        AND DATE(yape_trans.date_operation) = DATE(transactions.date_operation)
                        AND yape_trans.destination LIKE ?)", ["%$search%"]);
            });
        }

        if ($subCategory && $subCategory == 'without_sub_category') {
            $query->whereNull('transactions.sub_category_id');
        } elseif ($subCategory) {
            $query->where('transactions.sub_category_id', $subCategory);
        }
        $query->where('transactions.user_id', $userId);
        if ($recurring) {
            $query->orderBy('frequency', 'desc');
        }
        $data = $query->orderBy('transactions.date_operation', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json($data);
    }


    public function getSummaryBySubCategory(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 20);
        $page = $request->input('page', 1);
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

        if ($type) {
            $query->where('t.type_transaction', $type);
        }

        if ($userId) {
            $query->where('t.user_id', $userId);
        }

        $results = $query->groupBy('sc.name')
            ->orderBy(DB::raw('total'), 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json($results);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Transaction $transaction): JsonResponse
    {
        $data = $transaction->update($request->all());
        return response()->json($data);
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Transaction $transaction): JsonResponse
    {
        $data = $transaction->delete();
        return response()->json($data);
    }
}
