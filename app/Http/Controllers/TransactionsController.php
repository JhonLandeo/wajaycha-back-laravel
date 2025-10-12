<?php

namespace App\Http\Controllers;

use App\Exports\TransactionsExport;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
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

        $procedure = $recurring ? 'get_transactions_by_detail' : 'get_transactions';

        $statement = DB::select("select * from $procedure(?,?,?,?,?,?,?,?,?,?,?,?)", [
            $perPage,
            $page,
            $year,
            $month,
            $type,
            $amount,
            $search,
            $subCategory,
            $userId,
            $recurring,
            $weekend,
            $workday
        ]);

        foreach ($statement as $key => $value) {
            if (!$recurring) {
                $statement[$key]->yape_trans = json_decode($value->yape_trans);
            } else {
                $statement[$key]->child_transactions = json_decode($value->child_transactions);
            }
        }

        $total = 0;
        if(!empty($statement)){
            $total = $statement[0]->total_count;
        }

        $paginator = new LengthAwarePaginator(
            $statement,
            $total,
            $perPage,
            $page
        );

        return response()->json($paginator);
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
