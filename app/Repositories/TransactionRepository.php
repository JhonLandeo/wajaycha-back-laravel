<?php

declare(strict_types=1);

namespace App\Repositories;

use App\DTOs\Transactions\TransactionFilterDTO;
use App\Models\Transaction;
use App\Repositories\Contracts\TransactionRepositoryContract;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Support\Facades\DB;

class TransactionRepository implements TransactionRepositoryContract
{
    /**
     * @param TransactionFilterDTO $filters
     * @return LengthAwarePaginatorContract<int, \stdClass>
     */
    public function findPaginated(TransactionFilterDTO $filters): LengthAwarePaginatorContract
    {
        $function = $filters->recurring ? 'get_transactions_by_detail' : 'get_transactions';

        $statement = DB::select("select * from $function(?,?,?,?,?,?,?,?,?,?,?,?)", [
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
            $filters->workday
        ]);

        foreach ($statement as $key => $value) {
            if (!$filters->recurring) {
                $statement[$key]->yape_trans = json_decode((string) $value->yape_trans);
                $statement[$key]->tags = json_decode((string) $value->tags);
            } else {
                $statement[$key]->child_transactions = json_decode((string) $value->child_transactions);
            }
        }

        $total = 0;
        if (!empty($statement)) {
            $total = $statement[0]->total_count;
        }

        return new LengthAwarePaginator(
            $statement,
            $total,
            $filters->perPage,
            $filters->page
        );
    }

    /**
     * @param int $userId
     * @param int|null $year
     * @param int|null $month
     * @param string|null $type
     * @param int $perPage
     * @param int $page
     * @return LengthAwarePaginatorContract<int, \App\Models\Transaction>
     */
    public function summaryByCategory(int $userId, ?int $year, ?int $month, ?string $type, int $perPage, int $page): LengthAwarePaginatorContract
    {
        $query = Transaction::query()
            ->leftJoin('details as d', 'd.id', '=', 'transactions.detail_id')
            ->leftJoin('categories as sc', 'sc.id', '=', 'transactions.category_id')
            ->select(
                DB::raw("COALESCE(sc.name, 'Sin categorizar') as name"),
                DB::raw('COUNT(*) as quantity'),
                DB::raw(" SUM(CASE 
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

        $query->where('transactions.user_id', $userId);

        return $query->groupBy('sc.name')
            ->orderBy(DB::raw('total'), 'desc')
            ->paginate($perPage, ['*'], 'page', $page);
    }
}
