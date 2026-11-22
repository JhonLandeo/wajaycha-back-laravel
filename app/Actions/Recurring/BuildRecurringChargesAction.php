<?php

declare(strict_types=1);

namespace App\Actions\Recurring;

use App\DTOs\Recurring\RecurringChargeReport;
use App\Repositories\Contracts\RecurringChargeRepositoryContract;
use App\Services\Recurring\RecurringChargeDetector;
use Carbon\CarbonImmutable;

/**
 * Wires the recurring-charge reading: the repository supplies the movements, the
 * detector decides, and this class only owns the window and the configuration.
 *
 * The window is rolling and ends today, not at the last complete month. A
 * subscription charged on the 20th is already part of this month's cost and
 * hiding it until the month closes would make the answer wrong for ten days out
 * of thirty.
 */
final class BuildRecurringChargesAction
{
    public function __construct(
        private readonly RecurringChargeRepositoryContract $repository,
        private readonly RecurringChargeDetector $detector,
    ) {}

    public function execute(int $userId): RecurringChargeReport
    {
        $windowMonths = max((int) config('recurring.window_months'), 1);
        $timezone = (string) config('app.timezone');

        $now = CarbonImmutable::now($timezone);
        $startsAt = $now->subMonthsNoOverflow($windowMonths)->startOfDay();
        $endsAt = $now->addDay()->startOfDay();

        $charges = $this->detector->detect(
            charges: $this->repository->chargesBetween($userId, $startsAt, $endsAt),
            variationThreshold: (float) config('recurring.variation_threshold'),
            regularShare: (float) config('recurring.regular_share'),
            intervalToleranceShare: (float) config('recurring.interval_tolerance_share'),
            today: $now,
            timezone: $timezone,
        );

        return new RecurringChargeReport($charges, $windowMonths);
    }
}
