<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransactionsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
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

        $query = Transaction::with(['detail.user'])
            ->selectRaw("transactions.*, $subQuery")
            ->selectRaw("transactions.*, $subQueryFrequency")
            ->join('details as d', 'transactions.detail_id', '=',  'd.id');

        if ($year) {
            $query->whereYear('date_operation', $year);
        }

        if ($month) {
            $query->whereMonth('date_operation', $month);
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
        $query->where('user_id', $userId);
        $data = $query->orderBy('transactions.date_operation', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json($data);
    }


    public function getSummaryBySubCategory(Request $request)
    {
        $perPage = $request->input('per_page', 20);
        $page = $request->input('page', 1);
        $year = $request->input('year', null);
        $month = $request->input('month', null);
        $type = $request->input('type', null);
        $userId = $request->input('user_id', null);
    
        $query = Transaction::join('details as d', 'd.id', '=', 'transactions.detail_id')
            ->join('sub_categories as sc', 'sc.id', '=', 'd.sub_category_id')
            ->select(
                'sc.name',
                DB::raw('COUNT(*) as quantity'),
                DB::raw('SUM(transactions.amount) as total')
            );
    
        // Aplicamos los filtros condicionales
        if ($year) {
            $query->whereYear('transactions.date_operation', $year);
        }
        if ($month) {
            $query->whereMonth('transactions.date_operation', $month);
        }
        if ($type) {
            $query->where('transactions.type_transaction', $type);
        }
        if ($userId) {
            $query->where('d.user_id', $userId);
        }
    
        // Realizamos la paginación
        $results = $query->groupBy('sc.name')
            ->orderBy(DB::raw('SUM(transactions.amount)'), 'desc')
            ->paginate($perPage, ['*'], 'page', $page);
    
        // Devolvemos la respuesta en formato JSON
        return response()->json($results);
    }


    


    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        return Transaction::create($request->all());
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Transaction $transaction)
    {
        return $transaction->update($request->all());
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Transaction $transaction)
    {
        return $transaction->delete();
    }
}
