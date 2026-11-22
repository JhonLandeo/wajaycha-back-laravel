<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Carbon\CarbonImmutable;

interface RecurringChargeRepositoryContract
{
    /**
     * Expense rows per merchant inside a half-open interval:
     * `date_operation >= $startsAt AND date_operation < $endsAt`.
     *
     * Reads `v_unified_transactions`, never `transactions`, for the reason that
     * matters here: the view drops the row that arrived twice (`matched_
     * transaction_id IS NOT NULL`). A duplicate would otherwise look like two
     * charges in the same month and break the spacing test that decides whether
     * anything is recurring at all.
     *
     * The merchant is `entity_clean` when the categoriser filled it, and the raw
     * description when it did not. Both are the user's own labels for the same
     * place; the detector folds their case so the two spellings remain one
     * charge.
     *
     * @return array<int, object{merchant: string, amount: string, date_operation: string}>
     */
    public function chargesBetween(int $userId, CarbonImmutable $startsAt, CarbonImmutable $endsAt): array;
}
