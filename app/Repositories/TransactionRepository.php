<?php

declare(strict_types=1);

namespace App\Repositories;

use App\DTOs\Transactions\TransactionFilterDTO;
use App\Models\Transaction;
use App\Repositories\Contracts\TransactionRepositoryContract;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class TransactionRepository implements TransactionRepositoryContract
{
    /**
     * @return LengthAwarePaginatorContract<int, \stdClass>
     */
    public function findPaginated(TransactionFilterDTO $filters): LengthAwarePaginatorContract
    {
        $function = $filters->recurring ? 'get_transactions_by_detail' : 'get_transactions';

        // Rule 02 Violation Fix: Explicit columns instead of SELECT *
        if (! $filters->recurring) {
            $columns = 'id, message, amount, allocated_amount, date_operation, type_transaction, category_id, detail_id, detail_name, frequency_general, frequency, yape_trans, yape_id, user_id, source_type, suggested_category_id, suggest_name, tags, is_manual, total_count';
        } else {
            $columns = 'detail_id, detail_name, child_transactions, frequency, amount, total_count';
        }

        $statement = DB::select("SELECT $columns FROM $function(?,?,?,?,?,?,?,?,?,?,?,?)", [
            $filters->perPage,
            $filters->page,
            $filters->year,
            $filters->month,
            $filters->type,
            $filters->amount,
            $filters->search,
            $filters->category,
            $filters->userId,
            $filters->recurring,
            $filters->weekend,
            $filters->workday,
        ]);

        foreach ($statement as $key => $value) {
            if (! $filters->recurring) {
                if (property_exists($value, 'yape_trans')) {
                    $statement[$key]->yape_trans = json_decode((string) $value->yape_trans);
                }
                if (property_exists($value, 'tags')) {
                    $statement[$key]->tags = json_decode((string) $value->tags);
                }
            } else {
                if (property_exists($value, 'child_transactions')) {
                    $statement[$key]->child_transactions = json_decode((string) $value->child_transactions);
                }
            }
        }

        // `get_transactions` no conoce los splits: devuelve una fila por
        // transacción y su `category_id` es null cuando está repartida. El
        // listado necesita distinguir "repartido" de "sin categoría" para no
        // mentir en la fila. Una consulta por página alcanza.
        if (! $filters->recurring && $statement !== []) {
            $withSplits = DB::table('transaction_splits')
                ->whereIn('transaction_id', array_map(
                    static fn (object $row): int => (int) $row->id,
                    $statement,
                ))
                ->distinct()
                ->pluck('transaction_id')
                ->map(static fn ($id): int => (int) $id)
                ->flip();

            foreach ($statement as $row) {
                $row->has_splits = $withSplits->has((int) $row->id);
            }
        }

        $total = 0;
        if (! empty($statement)) {
            $total = (int) $statement[0]->total_count;
        }

        return new LengthAwarePaginator(
            $statement,
            $total,
            $filters->perPage,
            $filters->page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    /**
     * @return LengthAwarePaginatorContract<int, \App\Models\Transaction>
     */
    public function summaryByCategory(int $userId, ?int $year, ?int $month, ?string $type, int $perPage, int $page, ?string $search = null): LengthAwarePaginatorContract
    {
        // `v_transaction_allocations` entrega una fila por categoría y monto, así
        // que el resumen suma la parte asignada y no el ticket entero. También
        // incluye el movimiento sin categorizar con `category_id = NULL`, que cae
        // en el cubo "Sin categorizar" como antes. Un movimiento dividido nunca
        // aparece ahí: su categoría es NULL, pero sus splits lo excluyen de esa
        // rama de la vista.
        $totalExpression = "SUM(CASE
            WHEN a.type_transaction = 'expense' THEN a.allocated_amount
            WHEN a.type_transaction = 'income' THEN -a.allocated_amount
            ELSE 0
        END)";

        $query = DB::table('v_transaction_allocations as a')
            ->leftJoin('categories as c', 'c.id', '=', 'a.category_id')
            ->select(
                DB::raw("COALESCE(c.name, 'Sin categorizar') as name"),
                DB::raw('COUNT(*) as quantity'),
                DB::raw("{$totalExpression} as total")
            )
            ->where('a.user_id', $userId);

        if ($year) {
            $query->whereYear('a.date_operation', $year);
        }

        if ($month) {
            $query->whereMonth('a.date_operation', $month);
        }

        if ($type) {
            $query->where('a.type_transaction', $type);
        }

        if ($search) {
            $query->where('c.name', 'ILIKE', '%'.$search.'%');
        }

        /** @var LengthAwarePaginatorContract */
        return $query
            ->groupByRaw("COALESCE(c.name, 'Sin categorizar')")
            ->orderByRaw("{$totalExpression} DESC")
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function findById(int $id, int $userId): ?Transaction
    {
        /** @var Transaction|null */
        return Transaction::query()->whereKey($id)->where('user_id', $userId)->first();
    }

    public function create(array $data): Transaction
    {
        /** @var Transaction */
        return Transaction::query()->create($data);
    }

    public function update(Transaction $transaction, array $data): bool
    {
        return $transaction->update($data);
    }

    public function delete(Transaction $transaction): bool
    {
        return (bool) $transaction->delete();
    }

    public function topMerchantsForCategoryMonth(int $userId, int $categoryId, CarbonImmutable $startsAt, CarbonImmutable $endsAt, int $limit): array
    {
        // Rule 02 Violation Fix: Explicit columns instead of SELECT *
        // Deliberately v_transaction_allocations, never get_transactions_by_detail()
        // (design.md D5) — no HAVING, so a single-transaction merchant survives.
        // A split movement contributes its assigned amount, not the whole ticket.
        return DB::table('v_transaction_allocations')
            ->select(
                'detail_id',
                'detail_name',
                DB::raw('SUM(allocated_amount) as total'),
                DB::raw('COUNT(*) as frequency')
            )
            ->where('user_id', $userId)
            ->where('category_id', $categoryId)
            ->where('type_transaction', 'expense')
            ->where('date_operation', '>=', $startsAt)
            ->where('date_operation', '<', $endsAt)
            ->groupBy('detail_id', 'detail_name')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->all();
    }

    public function largestExpenseForCategoryMonth(int $userId, int $categoryId, CarbonImmutable $startsAt, CarbonImmutable $endsAt): ?object
    {
        // Rule 02 Violation Fix: Explicit columns instead of SELECT *
        return DB::table('v_transaction_allocations')
            ->select('detail_name', 'allocated_amount as amount', 'date_operation')
            ->where('user_id', $userId)
            ->where('category_id', $categoryId)
            ->where('type_transaction', 'expense')
            ->where('date_operation', '>=', $startsAt)
            ->where('date_operation', '<', $endsAt)
            ->orderByDesc('allocated_amount')
            ->limit(1)
            ->first();
    }

    public function expenseByCategoryBetween(int $userId, CarbonImmutable $startsAt, CarbonImmutable $endsAt): array
    {
        // Rule 02 Violation Fix: Explicit columns instead of SELECT *
        //
        // La vista incluye el movimiento sin categorizar como
        // `category_id = NULL`. El LEFT JOIN, nunca INNER, lo deja sobrevivir:
        // descartarlo haría que el total de la comparación quedara más chico que
        // el mes real, que es la forma menos visible de mentir.
        return DB::table('v_transaction_allocations as a')
            ->leftJoin('categories as c', 'c.id', '=', 'a.category_id')
            ->select(
                'a.category_id',
                'c.name as category_name',
                DB::raw('SUM(a.allocated_amount) as total')
            )
            ->where('a.user_id', $userId)
            ->where('a.type_transaction', 'expense')
            ->where('a.date_operation', '>=', $startsAt)
            ->where('a.date_operation', '<', $endsAt)
            ->groupBy('a.category_id', 'c.name')
            ->get()
            ->all();
    }

    public function replaceSplits(Transaction $transaction, array $lines): void
    {
        DB::transaction(function () use ($transaction, $lines): void {
            DB::table('transaction_splits')
                ->where('transaction_id', $transaction->id)
                ->delete();

            // Una transacción con reparto no tiene categoría propia. El trigger
            // diferido lo exige; escribir null acá mantiene la exclusión como
            // parte de la misma operación, no como un paso que el cliente olvida.
            $transaction->update(['category_id' => null]);

            if ($lines === []) {
                return;
            }

            $now = now();

            DB::table('transaction_splits')->insert(array_map(
                static fn (array $line): array => [
                    'transaction_id' => $transaction->id,
                    'category_id' => $line['category_id'],
                    'user_id' => $transaction->user_id,
                    'amount' => $line['amount'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $lines,
            ));
        });
    }

    public function clearSplits(Transaction $transaction, ?int $categoryId): void
    {
        DB::transaction(function () use ($transaction, $categoryId): void {
            DB::table('transaction_splits')
                ->where('transaction_id', $transaction->id)
                ->delete();

            $transaction->update(['category_id' => $categoryId]);
        });
    }

    public function reassignTags(int $fromTransactionId, int $toTransactionId): void
    {
        DB::table('transaction_tag')
            ->where('transaction_id', $fromTransactionId)
            ->update(['transaction_id' => $toTransactionId]);
    }
}
