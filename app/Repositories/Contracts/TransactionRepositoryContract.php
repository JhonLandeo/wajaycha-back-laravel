<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\DTOs\Transactions\TransactionFilterDTO;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;

interface TransactionRepositoryContract
{
    /**
     * @return LengthAwarePaginatorContract<int, \stdClass>
     */
    public function findPaginated(TransactionFilterDTO $filters): LengthAwarePaginatorContract;

    /**
     * @return LengthAwarePaginatorContract<int, \App\Models\Transaction>
     */
    public function summaryByCategory(int $userId, ?int $year, ?int $month, ?string $type, int $perPage, int $page, ?string $search = null): LengthAwarePaginatorContract;

    /**
     * Find a Transaction belonging to $userId, or null.
     *
     * $userId is an AUTHORIZATION BOUNDARY, not a convenience filter. Resolving by id
     * alone returns another user's record, which is how every endpoint in this API came
     * to be exploitable. A caller that cannot supply the owner has no business calling
     * this method.
     */
    public function findById(int $id, int $userId): ?Transaction;

    public function create(array $data): Transaction;

    public function update(Transaction $transaction, array $data): bool;

    public function delete(Transaction $transaction): bool;

    /**
     * Merchant cause breakdown for a category-month, read from
     * `v_transaction_allocations` (design.md D5) — the same source `spent`
     * comes from, so every percentage a message renders reconciles with the
     * amount beside it. A split movement contributes only the part assigned to
     * this category, never the whole ticket.
     * Deliberately NOT `get_transactions_by_detail()`: its
     * `HAVING COUNT(t.id) > 1` drops single-transaction merchants, which is
     * exactly the lumpy case a coaching message most needs to name.
     *
     * $startsAt/$endsAt form a half-open interval: `date_operation >= $startsAt
     * AND date_operation < $endsAt`.
     *
     * @return array<int, object{detail_id: int, detail_name: string, total: float, frequency: int}>
     *                                                                                               ordered by total spend descending, limited to $limit
     */
    public function topMerchantsForCategoryMonth(int $userId, int $categoryId, CarbonImmutable $startsAt, CarbonImmutable $endsAt, int $limit): array;

    /**
     * The single largest expense row for a category-month, from the same view as
     * above. `amount` is the part assigned to the category, so a shared ticket
     * does not look lumpy in every category it touches. Feeds `PaceEvaluator`'s
     * lumpiness decision and the lumpy message.
     *
     * @return object{detail_name: string, amount: float, date_operation: string}|null
     */
    public function largestExpenseForCategoryMonth(int $userId, int $categoryId, CarbonImmutable $startsAt, CarbonImmutable $endsAt): ?object;

    /**
     * Expense totals per category inside a half-open date interval:
     * `date_operation >= $startsAt AND date_operation < $endsAt`.
     *
     * Reads `v_transaction_allocations` like the two methods above, and for the
     * reason that matters most here: the view keeps only rows with
     * `matched_transaction_id IS NULL`, so a movement that arrived through both
     * Yape and the bank statement is counted once. Reading `transactions`
     * directly would make a month look larger than it was, and comparing two
     * months of that produces a change that never happened.
     *
     * A row with a null `category_id` survives: the view includes the
     * uncategorised movement with its full amount, and the categoriser does not
     * always have an answer. Dropping those rows would silently shrink the total
     * this comparison is built on.
     *
     * @return array<int, object{category_id: int|null, category_name: string|null, total: float}>
     */
    public function expenseByCategoryBetween(int $userId, CarbonImmutable $startsAt, CarbonImmutable $endsAt): array;

    /**
     * Replaces the whole split of a transaction with the given lines.
     *
     * Deletes the previous rows, sets `category_id` to null and inserts the new
     * ones atomically. The sum invariant and the mutual exclusion with
     * `category_id` are also enforced by deferred triggers; the caller validates
     * first so a bad request is a 422, not a SQL error.
     *
     * @param  array<int, array{category_id: int, amount: string}>  $lines
     */
    public function replaceSplits(Transaction $transaction, array $lines): void;

    /**
     * Removes the split and leaves the transaction with `$categoryId`, which may
     * be null (uncategorised is a valid state).
     */
    public function clearSplits(Transaction $transaction, ?int $categoryId): void;

    /**
     * Move every tag attached to one transaction onto another.
     *
     * Used when a PDF statement line is matched to a Yape movement already in the
     * ledger: the Yape row keeps existing, but the tags the user put on it belong to the
     * transaction that survives. Losing them silently would strip a user's own labelling
     * off their history with nothing to show what happened.
     */
    public function reassignTags(int $fromTransactionId, int $toTransactionId): void;
}
