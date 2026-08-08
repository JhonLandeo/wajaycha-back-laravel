<?php

declare(strict_types=1);

namespace App\Repositories;

use App\DTOs\Dashboard\DashboardScope;
use App\Enums\DashboardMeasure;
use App\Models\UnifyTransactions;
use App\Repositories\Contracts\DashboardRepositoryContract;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL adapter for the dashboard read model.
 *
 * The queries are the ones the controller used to hold, moved without alteration —
 * same functions, same arguments, same JSON. What changed is who owns them and how
 * the owner is addressed.
 */
class DashboardRepository implements DashboardRepositoryContract
{
    /**
     * @return array<string, mixed>
     */
    public function kpi(DashboardScope $scope): array
    {
        return $this->decodeObject('SELECT get_kpi_data(?, ?, ?) as data', [
            $scope->userId,
            $scope->year,
            $scope->month,
        ]);
    }

    /**
     * @return array<int|string, mixed>
     */
    public function topFive(DashboardScope $scope): array
    {
        return $this->decodeList('SELECT get_top_five_data(?, ?, ?) as data', [
            $scope->userId,
            $scope->year,
            $scope->month,
        ]);
    }

    /**
     * @return array<int|string, mixed>
     */
    public function weekly(DashboardScope $scope, DashboardMeasure $measure): array
    {
        return $this->decodeList('SELECT get_weekly_data(?, ?, ?, ?) as data', [
            $scope->userId,
            $measure->asLegacyFlag(),
            $scope->year,
            $scope->month,
        ]);
    }

    /**
     * @return array<int|string, mixed>
     */
    public function monthly(DashboardScope $scope, DashboardMeasure $measure): array
    {
        return $this->decodeList('SELECT get_monthly_data(?, ?, ?) as data', [
            $scope->userId,
            $measure->asLegacyFlag(),
            $scope->year,
        ]);
    }

    /**
     * @return array<int, array{name: string, quantity: int, total: string}>
     */
    public function spendByCategory(DashboardScope $scope, ?string $search = null): array
    {
        $nameExpression = "COALESCE(c.name, 'Sin categorizar')";
        $totalExpression = "SUM(CASE
        WHEN t.type_transaction = 'expense' THEN t.amount
        WHEN t.type_transaction = 'income' THEN -t.amount
        ELSE 0
        END)";

        $query = UnifyTransactions::query()
            ->from('v_unified_transactions as t')
            ->leftJoin('details as d', 'd.id', '=', 't.detail_id')
            ->leftJoin('categories as c', 'c.id', '=', 't.category_id')
            ->select(
                DB::raw("{$nameExpression} as name"),
                DB::raw('COUNT(*) as quantity'),
                DB::raw("{$totalExpression} as total")
            )
            // Not conditional. Every other filter below is optional; ownership is not.
            ->where('t.user_id', $scope->userId);

        if ($scope->year) {
            $query->whereYear('t.date_operation', $scope->year);
        }

        if ($scope->month) {
            $query->whereMonth('t.date_operation', $scope->month);
        }

        if ($search) {
            $query->where('c.name', 'ILIKE', '%'.$search.'%');
        }

        /** @var array<int, array{name: string, quantity: int, total: string}> */
        return $query
            ->groupByRaw($nameExpression)
            ->havingRaw("{$totalExpression} > 0")
            ->orderByRaw("{$totalExpression} DESC")
            ->get()
            ->toArray();
    }

    /**
     * @param  array<int, mixed>  $bindings
     * @return array<string, mixed>
     */
    private function decodeObject(string $statement, array $bindings): array
    {
        return $this->decode($statement, $bindings);
    }

    /**
     * @param  array<int, mixed>  $bindings
     * @return array<int|string, mixed>
     */
    private function decodeList(string $statement, array $bindings): array
    {
        return $this->decode($statement, $bindings);
    }

    /**
     * These functions return a single JSON column. `null` is a real answer — a period
     * with no rows — so it becomes an empty array rather than reaching the caller as a
     * null the response would have to guess about.
     *
     * @param  array<int, mixed>  $bindings
     * @return array<int|string, mixed>
     */
    private function decode(string $statement, array $bindings): array
    {
        $rows = DB::select($statement, $bindings);

        if ($rows === []) {
            return [];
        }

        $payload = $rows[0]->data ?? null;

        if (! is_string($payload)) {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }
}
