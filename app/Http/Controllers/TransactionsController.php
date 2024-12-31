<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;
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

        // Subconsulta para obtener el JSON agregado
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
              AND DATE(yape_trans.date_operation) = DATE(transactions.date_operation)
            ) AS relation
        ";

        // Construir la consulta principal
        $query = Transaction::with(['detail.user', 'subCategory.category'])
            ->selectRaw("transactions.*, $subQuery");

        if ($year) {
            $query->whereYear('date_operation', $year);
        }

        if ($month) {
            $query->whereMonth('date_operation', $month);
        }

        if ($type) {
            $query->where('type_transaction', $type);
        }

        $data = $query->orderBy('date_operation', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json($data);
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
