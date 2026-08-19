<?php

declare(strict_types=1);

namespace App\DTOs\Pareto;

/**
 * A Pareto classification stripped to what the report reads: its identity and the
 * share of the budget the user declared for it.
 *
 * A row rather than the model, so `ParetoReportBuilder` receives values and can be
 * tested against a fixed set of them without a database — the same reason
 * `CategoryMonthSnapshot` exists for `PaceEvaluator` (ADR-0009).
 */
final class ParetoBandRow
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly float $percentage,
    ) {}
}
