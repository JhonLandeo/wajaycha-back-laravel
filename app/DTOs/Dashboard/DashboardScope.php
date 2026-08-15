<?php

declare(strict_types=1);

namespace App\DTOs\Dashboard;

/**
 * Who is asking, and for which period.
 *
 * `$userId` is an authorization boundary, not a filter of convenience — the same
 * rule `ParetoRepositoryContract` already states. Carrying it inside the scope
 * rather than as a loose positional int removes a specific failure this code was
 * exposed to: `get_kpi_data` takes `(user_id, year, month)` while `get_weekly_data`
 * takes `(user_id, is_checked, year, month)`, and every call site passed a bare
 * array in the order the function happened to want.
 *
 * A transposed argument there does not throw. It silently answers with a different
 * user's figures, or with an empty period that looks like a quiet month.
 */
final readonly class DashboardScope
{
    public function __construct(
        public int $userId,
        public ?int $year = null,
        public ?int $month = null,
    ) {}
}
