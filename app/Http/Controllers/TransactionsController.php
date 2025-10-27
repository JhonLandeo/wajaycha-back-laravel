<?php

namespace App\Http\Controllers;

use App\Exports\TransactionsExport;
use App\Jobs\GenerateEmbeddingForDetail;
use App\Models\Detail;
use App\Models\Transaction;
use App\Models\TransactionYape;
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

        $procedure = $recurring ? 'get_transactions_by_detail' : 'get_transactions';

        $statement = DB::select("select * from $procedure(?,?,?,?,?,?,?,?,?,?,?,?)", [
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

        $query = DB::table('transactions as t')
            ->leftJoin('details as d', 'd.id', '=', 't.detail_id')
            ->leftJoin('categories as sc', 'sc.id', '=', 't.category_id')
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
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'category_id' => 'required|integer|exists:categories,id',
            'isUpdateAll' => 'boolean',
        ]);

        $newCategoryId = (int)$request->category_id;

        if ($request->isUpdateAll) {
            $this->handleBatchUpdate($request, $newCategoryId);
        } else {
            $this->handleSingleUpdate($request, $newCategoryId);
        }

        return response()->json(['status' => 'ok'], 201);
    }

    /**
     * Maneja la lógica de actualización masiva (isUpdateAll = true)
     */
    private function handleBatchUpdate(Request $request, int $newCategoryId): void
    {
        // 1. Obtenemos todas las transacciones que coinciden
        //    Usamos Eloquent para poder acceder a los modelos y sus relaciones
        $transactions = Transaction::query()
            ->join('details as d', 'transactions.detail_id', '=', 'd.id')
            ->where('d.description', $request->name)
            ->when($request->month, fn($q) => $q->whereMonth('transactions.date_operation', $request->month))
            ->when($request->year, fn($q) => $q->whereYear('transactions.date_operation', $request->year))
            ->select('transactions.*') // Solo necesitamos los datos de la transacción
            ->get();

        $detailIdsToLearn = [];

        foreach ($transactions as $transaction) {
            // 2. Actualizamos la transacción principal
            $transaction->category_id = $newCategoryId;
            $transaction->save();

            // 3. Registramos el detail_id para aprender de él (usamos keys para unicidad)
            $detailIdsToLearn[$transaction->detail_id] = true;

            // 4. Actualizamos la transacción Yape correspondiente (tu lógica)
            $this->updateMatchingYapeTransaction($transaction, $newCategoryId);
        }

        // --- 💡 MOMENTO DE APRENDIZAJE MASIVO ---
        // Despachamos un Job por cada "Detail" único que actualizamos,
        // no por cada "Transaction".
        foreach (array_keys($detailIdsToLearn) as $detailId) {
            $detail = Detail::find($detailId);
            if ($detail) {
                GenerateEmbeddingForDetail::dispatch($detail, $newCategoryId);
            }
        }
    }

    /**
     * Maneja la lógica de actualización única (isUpdateAll = false)
     */
    private function handleSingleUpdate(Request $request, int $newCategoryId): void
    {
        if ($request->source_type == 'yape_unmatched') {
            // Caso especial: Solo actualiza Yape
            // (Aquí no hay "Detail", por lo que no hay aprendizaje vectorial)
            TransactionYape::where('id', $request->transaction_id)
                ->update(['category_id' => $newCategoryId]);
        } else {
            // Caso normal: Actualiza una transacción de la importación
            $transaction = Transaction::find($request->transaction_id);

            if ($transaction) {
                // 1. Actualizamos la transacción principal
                $transaction->category_id = $newCategoryId;
                $transaction->save();

                // --- 💡 MOMENTO DE APRENDIZAJE ÚNICO ---
                // Cargamos su "detail" y despachamos el Job para aprender
                $transaction->load('detail');
                if ($transaction->detail) {
                    GenerateEmbeddingForDetail::dispatch($transaction->detail, $newCategoryId);
                }

                // 2. Actualizamos la transacción Yape correspondiente (tu lógica)
                $this->updateMatchingYapeTransaction($transaction, $newCategoryId);
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
