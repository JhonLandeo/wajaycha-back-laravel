<?php

namespace App\Http\Controllers;

use App\Models\Detail;
use App\Models\Details;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Js;

class DetailsController extends Controller
{
    public function updateNameCommon(Request $request): JsonResponse
    {
        if ($request->isUpdateAll) {
            $transactionCommon = DB::table('transactions as t')
                ->select('t.*')
                ->join('details as d', 't.detail_id', '=', 'd.id')
                ->where('d.name', $request->name)
                ->get();
    
            $transactionCommon->each(function ($item) use ($request) {
    
                Transaction::where('id', $item->id)
                    ->update([
                        'sub_category_id' => $request->sub_category_id,
                    ]);
            });
    
        } else {
            Transaction::where('id', $request->transaction_id)
                ->update([
                    'sub_category_id' => $request->sub_category_id,
                ]);
        }
    
        return response()->json(['status' => 'ok'], 201);
    }
    
}
