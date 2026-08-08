<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\DTOs\Dashboard\DashboardScope;
use App\Enums\DashboardMeasure;

/**
 * The dashboard read model.
 *
 * This is a query port and nothing else. It carries no business rule, returns no
 * entity, and every method is a read — which is why the PostgreSQL functions behind
 * it are the right tool and are not going anywhere. Denormalised, ORM-free reads are
 * what a read side is supposed to look like; the defect was never that the queries
 * were SQL, it was that the controller was the one holding them.
 *
 * The distinction that matters: these figures may be *rendered*. They may not be used
 * to reach a conclusion. A stored procedure can return 82%, but it cannot return why
 * 82% matters — see ADR-0009. Anything that decides something reads through a domain
 * service instead, the way `PaceEvaluator` consumes `CategoryMonthSnapshot`.
 *
 * Return types are plain decoded structures on purpose. Wrapping a read model in
 * typed objects on its way to a JSON response buys nothing and costs a mapping layer
 * that has to change every time the SPA wants another column.
 */
interface DashboardRepositoryContract
{
    /**
     * Income, expense, balance and daily averages for the period.
     *
     * @return array<string, mixed>
     */
    public function kpi(DashboardScope $scope): array;

    /**
     * The five largest movements of the period.
     *
     * @return array<int|string, mixed>
     */
    public function topFive(DashboardScope $scope): array;

    /**
     * A per-weekday series for the period.
     *
     * @return array<int|string, mixed>
     */
    public function weekly(DashboardScope $scope, DashboardMeasure $measure): array;

    /**
     * A per-month series for the year in $scope.
     *
     * @return array<int|string, mixed>
     */
    public function monthly(DashboardScope $scope, DashboardMeasure $measure): array;

    /**
     * Net spend grouped by category, largest first, uncategorised included.
     *
     * @return array<int, array{name: string, quantity: int, total: string}>
     */
    public function spendByCategory(DashboardScope $scope, ?string $search = null): array;
}
