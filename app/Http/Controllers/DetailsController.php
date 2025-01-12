<?php

namespace App\Http\Controllers;

use App\Models\Detail;
use App\Models\Details;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DetailsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
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
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Details $details)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Details $details)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Details $details)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Details $details)
    {
        //
    }

    public function updateNameCommon(Request $request)
    {
        if ($request->isUpdateAll) {
            $transactionCommon = DB::table('transactions as t')
                ->select('t.*')
                ->join('details as d', 't.detail_id', '=', 'd.id')
                ->where('d.name', $request->name)
                ->get();
    
            $transactionCommon->each(function ($item) use ($request) { // Pasar $request con "use"
                // Log::info('Transaction ID: ' . json_encode($item));
                // Log::info($item['id']);
    
                Transaction::where('id', $item->id) // Acceder con "->"
                    ->update([
                        'sub_category_id' => $request->sub_category_id,
                    ]);
            });
    
            Log::info('Transaction Common: ' . json_encode($transactionCommon));
        } else {
            Transaction::where('id', $request->transaction_id)
                ->update([
                    'sub_category_id' => $request->sub_category_id,
                ]);
        }
    
        return response()->json(['status' => 'ok'], 201);
    }
    
}
