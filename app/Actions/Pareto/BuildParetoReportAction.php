<?php

declare(strict_types=1);

namespace App\Actions\Pareto;

use App\DTOs\Pareto\ParetoBandReport;
use App\DTOs\Pareto\ParetoWindow;
use App\Repositories\Contracts\ParetoRepositoryContract;
use App\Services\Pareto\ParetoReportBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * Assembles the Pareto report: reads through the repository, decides through the
 * builder, paginates the result.
 *
 * It orchestrates and does not decide, which is the whole reason it is separate from
 * {@see ParetoReportBuilder}. Every rule about envelopes and windows lives in the
 * builder, where a test can hand it categories instead of seeding a database — the
 * arrangement [ADR-0009](../../../docs/decisions/0009-coach-narrates-does-not-advise.md)
 * asks for, and the one `get_pareto_monthly_report` could not offer.
 *
 * Pagination happens in PHP over a handful of bands. That is not a shortcut: the
 * share each band holds is taken from the total across ALL of them, so a page cannot
 * be computed without the others anyway.
 */
final class BuildParetoReportAction
{
    public function __construct(
        private readonly ParetoRepositoryContract $repository,
        private readonly ParetoReportBuilder $builder,
    ) {}

    public function execute(int $userId, ?int $month, ?int $year, int $page, int $perPage): LengthAwarePaginator
    {
        $window = ParetoWindow::forFilter(
            month: $month,
            year: $year,
            monthsWithActivity: $this->repository->monthsWithActivity($userId),
            today: Carbon::now(),
        );

        $reports = $this->builder->build(
            bands: $this->repository->bandRowsForUser($userId),
            categories: $this->repository->budgetedLeafCategories($userId),
            spentInWindow: $this->repository->netSpendByCategory($userId, $month, $year),
            // Envelopes ignore the month: how much of a yearly envelope is left is a
            // question about the year.
            spentInYear: $this->repository->netSpendByCategory($userId, null, $year),
            window: $window,
            totals: $this->repository->windowTotals($userId, $month, $year),
        );

        $page = max($page, 1);

        return new LengthAwarePaginator(
            array_map(
                static fn (ParetoBandReport $report): array => $report->toArray(),
                array_slice($reports, ($page - 1) * $perPage, $perPage)
            ),
            count($reports),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }
}
