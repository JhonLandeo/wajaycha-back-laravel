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
            $columns = 'id, message, amount, date_operation, type_transaction, category_id, detail_id, detail_name, frequency_general, frequency, yape_trans, yape_id, user_id, source_type, suggested_category_id, suggest_name, tags, is_manual, total_count';
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
        $query = Transaction::query()
            ->leftJoin('details as d', 'd.id', '=', 'transactions.detail_id')
            ->leftJoin('categories as sc', 'sc.id', '=', 'transactions.category_id')
            ->select(
                DB::raw("COALESCE(sc.name, 'Sin categorizar') as name"),
                DB::raw('COUNT(*) as quantity'),
                DB::raw("SUM(CASE 
                    WHEN transactions.type_transaction = 'expense' THEN transactions.amount 
                    WHEN transactions.type_transaction = 'income' THEN -transactions.amount 
                    ELSE 0 
                END) as total")
            );

        if ($year) {
            $query->whereYear('transactions.date_operation', $year);
        }

        if ($month) {
            $query->whereMonth('transactions.date_operation', $month);
        }

        if ($type) {
            $query->where('transactions.type_transaction', $type);
        }

        if ($search) {
            $query->where('sc.name', 'ILIKE', '%'.$search.'%');
        }

        $query->where('transactions.user_id', $userId);

        /** @var LengthAwarePaginatorContract */
        return $query->groupBy('sc.name')
            ->orderBy(DB::raw('total'), 'desc')
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
        // Deliberately v_unified_transactions, never get_transactions_by_detail()
        // (design.md D5) — no HAVING, so a single-transaction merchant survives.
        return DB::table('v_unified_transactions')
            ->select(
                'detail_id',
                'detail_name',
                DB::raw('SUM(amount) as total'),
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
        return DB::table('v_unified_transactions')
            ->select('detail_name', 'amount', 'date_operation')
            ->where('user_id', $userId)
            ->where('category_id', $categoryId)
            ->where('type_transaction', 'expense')
            ->where('date_operation', '>=', $startsAt)
            ->where('date_operation', '<', $endsAt)
            ->orderByDesc('amount')
            ->limit(1)
            ->first();
    }

    public function reassignTags(int $fromTransactionId, int $toTransactionId): void
    {
        DB::table('transaction_tag')
            ->where('transaction_id', $fromTransactionId)
            ->update(['transaction_id' => $toTransactionId]);
    }
}
