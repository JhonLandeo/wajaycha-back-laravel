<?php

namespace App\Http\Controllers;

use App\Models\Detail;
use App\Models\Details;
use App\Models\Transaction;
use App\Models\TransactionYape;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Js;

class DetailsController extends Controller
{
    public function updateNameCommon(Request $request): JsonResponse
    {
        if ($request->isUpdateAll) {
            $transactionCommon = DB::table('transactions as t')
                ->select('t.*', 'd.name')
                ->join('details as d', 't.detail_id', '=', 'd.id')
                ->where('d.name', $request->name)
                ->when($request->month, function ($query) use ($request) {
                    $query->whereMonth('t.date_operation', $request->month);
                })
                ->when($request->year, function ($query) use ($request) {
                    $query->whereYear('t.date_operation', $request->year);
                })
                ->get();

            $transactionCommon->each(function ($item) use ($request) {
                $monthTransaction = Carbon::parse($item->date_operation)->format('m');
                $yearTransaction = Carbon::parse($item->date_operation)->format('Y');
                $dayTransaction = Carbon::parse($item->date_operation)->format('d');

                $yapeTransaction = TransactionYape::where('amount', $item->amount)
                    ->where('type_transaction', $item->type_transaction)
                    ->whereMonth('date_operation', $monthTransaction)
                    ->whereYear('date_operation', $yearTransaction)
                    ->whereDay('date_operation', $dayTransaction)
                    ->first();

                if ($yapeTransaction) {
                    $yapeTransaction->category_id = $request->category_id;
                    $yapeTransaction->save();
                }

                Transaction::where('id', $item->id)
                    ->update([
                        'category_id' => $request->category_id,
                    ]);
            });
        } else {
            if ($request->source_type == 'yape_unmatched') {
                TransactionYape::where('id', $request->transaction_id)
                    ->update([
                        'category_id' => $request->category_id,
                    ]);
            } else {
                $transaction = Transaction::where('id', $request->transaction_id)
                    ->first();
                if ($transaction) {
                    $transaction->category_id = $request->category_id;
                    $transaction->save();
                    $monthTransaction = Carbon::parse($transaction->date_operation)->format('m');
                    $yearTransaction = Carbon::parse($transaction->date_operation)->format('Y');
                    $dayTransaction = Carbon::parse($transaction->date_operation)->format('d');

                    $yapeTransaction = TransactionYape::where('amount', $transaction->amount)
                        ->where('type_transaction', $transaction->type_transaction)
                        ->whereMonth('date_operation', $monthTransaction)
                        ->whereYear('date_operation', $yearTransaction)
                        ->whereDay('date_operation', $dayTransaction)
                        ->first();
                    if ($yapeTransaction) {
                        $yapeTransaction->category_id = $request->category_id;
                        $yapeTransaction->save();
                    }
                }
            }
        }

        return response()->json(['status' => 'ok'], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 10);
        $page = $request->input('page', 1);
        $statement = 'call get_details(?, ?)';
        $params = [$perPage, $page];
        $data = DB::select($statement, $params);
        if (count($data) > 0) {
            $total = $data[0]->total_count;
        }
        $paginate = new LengthAwarePaginator($data, $total ?? 0, $perPage, $page);

        return response()->json($paginate);
    }

    public function update(Request $request, Detail $detail): JsonResponse
    {
        Log::info($detail);
        Log::info($request->all());
        $data = $detail->update($request->all());
        return response()->json($data);
    }
}
