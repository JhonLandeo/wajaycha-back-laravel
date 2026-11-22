<?php

declare(strict_types=1);

namespace App\Repositories;

use App\DTOs\Dashboard\DashboardScope;
use App\Enums\DashboardMeasure;
use App\Repositories\Contracts\DashboardRepositoryContract;
use Illuminate\Support\Carbon;
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
        WHEN a.type_transaction = 'expense' THEN a.allocated_amount
        WHEN a.type_transaction = 'income' THEN -a.allocated_amount
        ELSE 0
        END)";

        // La vista trae una fila por categoría y monto, incluido el movimiento
        // sin categorizar (`category_id = NULL`), que conserva su cubo. Así el
        // dashboard no sobrestima la categoría con el ticket completo de una
        // compra repartida.
        $query = DB::table('v_transaction_allocations as a')
            ->leftJoin('categories as c', 'c.id', '=', 'a.category_id')
            ->select(
                DB::raw("{$nameExpression} as name"),
                DB::raw('COUNT(*) as quantity'),
                DB::raw("{$totalExpression} as total")
            )
            // Not conditional. Every other filter below is optional; ownership is not.
            ->where('a.user_id', $scope->userId);

        if ($scope->year) {
            $query->whereYear('a.date_operation', $scope->year);
        }

        if ($scope->month) {
            $query->whereMonth('a.date_operation', $scope->month);
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
     * @param  array<int, int>  $categoryIds
     * @return array<int, float>
     */
    public function dailyExpenseForCategories(int $userId, int $year, int $month, array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $from = Carbon::create($year, $month, 1)->startOfDay();
        $to = $from->copy()->addMonth();

        $rows = DB::table('v_transaction_allocations')
            ->selectRaw('EXTRACT(DAY FROM date_operation)::int AS day')
            ->selectRaw('COALESCE(SUM(allocated_amount), 0) AS amount')
            ->where('user_id', $userId)
            ->where('type_transaction', 'expense')
            ->whereIn('category_id', $categoryIds)
            // Half-open range on the raw column: wrapping it in a function for the
            // year/month test would discard the index. The GROUP BY needs EXTRACT
            // anyway, but the predicate that prunes rows does not.
            ->where('date_operation', '>=', $from)
            ->where('date_operation', '<', $to)
            ->groupByRaw('EXTRACT(DAY FROM date_operation)::int')
            ->orderBy('day')
            ->get();

        $daily = [];
        foreach ($rows as $row) {
            $daily[(int) $row->day] = (float) $row->amount;
        }

        return $daily;
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
