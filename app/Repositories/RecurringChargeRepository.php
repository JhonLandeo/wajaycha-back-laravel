<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Repositories\Contracts\RecurringChargeRepositoryContract;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class RecurringChargeRepository implements RecurringChargeRepositoryContract
{
    /**
     * @return array<int, object{merchant: string, amount: string, date_operation: string}>
     */
    public function chargesBetween(int $userId, CarbonImmutable $startsAt, CarbonImmutable $endsAt): array
    {
        // Explicit columns, half-open dates and the user filter first: the
        // `idx_transactions_user_date` index answers the range and the join to
        // `details` is a primary-key lookup.
        return DB::table('v_unified_transactions as ut')
            ->join('details as d', 'd.id', '=', 'ut.detail_id')
            ->select(
                DB::raw("COALESCE(NULLIF(TRIM(d.entity_clean), ''), d.description) as merchant"),
                'ut.amount',
                'ut.date_operation',
            )
            ->where('ut.user_id', $userId)
            ->where('ut.type_transaction', 'expense')
            ->where('ut.date_operation', '>=', $startsAt)
            ->where('ut.date_operation', '<', $endsAt)
            ->orderBy('ut.date_operation')
            ->get()
            ->all();
    }
}
