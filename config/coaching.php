<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Max Categories Per Report
    |--------------------------------------------------------------------------
    |
    | `get_monthly_category_budget_report` is paginated. CategoryRepository::
    | expenseBudgetSnapshotsForMonth() calls it with this value as `p_per_page`
    | and throws when the function's own `total_records` exceeds it, rather than
    | silently coaching a truncated category list (design.md D2, item 1).
    |
    | NOTE: this file is intentionally partial. Phase 2 (financial-coaching-pace)
    | adds only the key it needs. Phase 4 (task 4.1) EXTENDS this same file with
    | the remaining thresholds (min_day_for_projection, overrun_margin,
    | lumpy_share, max_observations_per_message, channels, COACHING_ENABLED,
    | COACHING_MAX_OBSERVATIONS) — it does not create it.
    |
    */

    'max_categories' => (int) env('COACHING_MAX_CATEGORIES', 500),

];
