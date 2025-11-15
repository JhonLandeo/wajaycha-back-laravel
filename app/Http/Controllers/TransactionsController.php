<?php

namespace App\Http\Controllers;

use App\Exports\TransactionsExport;
use App\Jobs\GenerateEmbeddingForDetail;
use App\Models\Detail;
use App\Models\Transaction;
use App\Models\TransactionTag;
use App\Models\TransactionYape;
use App\Services\CategorizationService;
use App\Services\ClassificationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
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
        $category = $request->input('category', null);
        $userId = Auth::id();
        $recurring = filter_var($request->input('recurring', false), FILTER_VALIDATE_BOOLEAN);
        $weekend = filter_var($request->input('weekend', false), FILTER_VALIDATE_BOOLEAN);
        $workday = filter_var($request->input('workday', false), FILTER_VALIDATE_BOOLEAN);

        $function = $recurring ? 'get_transactions_by_detail' : 'get_transactions';

        $statement = DB::select("select * from $function(?,?,?,?,?,?,?,?,?,?,?,?)", [
            $perPage,
            $page,
            $year,
            $month,
            $type,
            $amount,
            $search,
            $category,
            $userId,
            $recurring,
            $weekend,
            $workday
        ]);

        foreach ($statement as $key => $value) {
            if (!$recurring) {
                $statement[$key]->yape_trans = json_decode($value->yape_trans);
                $statement[$key]->tags = json_decode($value->tags);
            } else {
                $statement[$key]->child_transactions = json_decode($value->child_transactions);
            }
        }

        $total = 0;
        if (!empty($statement)) {
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

    public function getSummaryByCategory(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 20);
        $page = $request->input('page', 1);
        $year = $request->input('year', null);
        $month = $request->input('month', null);
        $type = $request->input('type', null);
        $userId = Auth::id();

        $query = Transaction::query()
            ->leftJoin('details as d', 'd.id', '=', 'transactions.detail_id')
            ->leftJoin('categories as sc', 'sc.id', '=', 'transactions.category_id')
            ->select(
                DB::raw('COALESCE(sc.name, "Sin categorizar") as name'),
                DB::raw('COUNT(*) as quantity'),
                DB::raw(" SUM(CASE 
                    WHEN transactions.type_transaction = 'expense' THEN transactions.amount 
                    WHEN transactions.type_transaction = 'income' THEN -transactions.amount 
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
    public function update(Request $request, CategorizationService $categorizationService, ClassificationService $classifier): JsonResponse
    {
        $request->validate([
            'category_id' => 'required|integer|exists:categories,id',
            'is_frequent' => 'boolean',
        ]);

        $newCategoryId = (int)$request->category_id;

        if ($request->is_frequent) {
            $this->updateTransactionFrequent($request, $newCategoryId, $categorizationService);
        } else {
            $this->updateTransactionWithoutFrequent($request, $newCategoryId, $classifier);
        }

        return response()->json(['status' => 'ok'], 201);
    }

    /**
     * Maneja la lógica de actualización masiva (isUpdateAll = true)
     */
    private function updateTransactionFrequent(Request $request, int $newCategoryId, CategorizationService $categorizationService): void
    {
        if ($request->source_type == 'yape_unmatched') {
            $yapeTransaction = TransactionYape::find($request->transaction_id);
            if ($yapeTransaction) {
                $yapeTransaction->category_id = $newCategoryId;
                $yapeTransaction->save();

                // Guardar el tag asociado
                if ($request->reason === 'with_reason') {
                    $transactionTag = new TransactionTag();
                    $transactionTag->transaction_yape_id = $yapeTransaction->id;
                    $transactionTag->tag_id = $request->tag_id;
                    $transactionTag->save();
                }

                $yapeTransaction->load('detail');
                $detail = $yapeTransaction->detail;
                TransactionYape::query()
                    ->join('details as d', 'transaction_yapes.detail_id', '=', 'd.id')
                    ->where('d.description', $detail->description)
                    ->whereNull('transaction_yapes.category_id')
                    ->update(['category_id' => $newCategoryId]);

                $categorizationService->createExactRule(
                    $yapeTransaction->user_id,
                    $detail->id,
                    $newCategoryId
                );
            }
        } else {
            $transaction = Transaction::find($request->transaction_id);
            if ($transaction) {
                $transaction->category_id =  $newCategoryId;;
                $transaction->save();
            }
            $transaction->load('detail');
            $detail = $transaction->detail;

            Transaction::query()
                ->join('details as d', 'transactions.detail_id', '=', 'd.id')
                ->where('d.description', $detail->description)
                ->whereNull('transactions.category_id')
                ->update(['category_id' => $newCategoryId]);

            $categorizationService->createExactRule(
                $transaction->user_id,
                $detail->id,
                $newCategoryId
            );
        }
    }


    private function updateTransactionWithoutFrequent(Request $request, int $newCategoryId, ClassificationService $classifier): void
    {
        if ($request->source_type == 'yape_unmatched') {
            $yapeTransaction = TransactionYape::find($request->transaction_id);
            if ($yapeTransaction) {
                $yapeTransaction->category_id = $newCategoryId;
                $yapeTransaction->save();
                // Guardar el tag asociado
                if ($request->reason === 'with_reason') {
                    $transactionTag = new TransactionTag();
                    $transactionTag->transaction_yape_id = $yapeTransaction->id;
                    $transactionTag->tag_id = $request->tag_id;
                    $transactionTag->save();
                }
                $yapeTransaction->load('detail');
                $detail = $yapeTransaction->detail;
                if ($classifier->isDetailUsefulForLearning($detail->description)) {
                    GenerateEmbeddingForDetail::dispatch($detail, $newCategoryId);
                }
            }
        } else {
            $transaction = Transaction::find($request->transaction_id);

            if ($transaction) {
                $transaction->category_id = $newCategoryId;
                $transaction->save();
                $transaction->load('detail');
                $detail = $transaction->detail;
                $this->updateMatchingYapeTransaction($transaction, $newCategoryId);
                if ($classifier->isDetailUsefulForLearning($detail->description)) {
                    GenerateEmbeddingForDetail::dispatch($detail, $newCategoryId);
                }
            }
        }
    }

    /**
     * Helper centralizado para mantener tu lógica de Yape sincronizada.
     * Busca y actualiza una transacción Yape que coincida en fecha, monto y tipo.
     */
    private function updateMatchingYapeTransaction(Transaction $transaction, int $newCategoryId): void
    {
        // Usar whereDate es más limpio y eficiente que D/M/Y por separado
        $yapeTransaction = TransactionYape::where('amount', $transaction->amount)
            ->where('user_id', Auth::id())
            ->where('type_transaction', $transaction->type_transaction)
            ->whereDate('date_operation', Carbon::parse($transaction->date_operation)->toDateString())
            ->first();

        if ($yapeTransaction) {
            $yapeTransaction->category_id = $newCategoryId;
            $yapeTransaction->save();
        }
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
