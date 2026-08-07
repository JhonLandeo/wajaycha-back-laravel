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
    */

    'max_categories' => (int) env('COACHING_MAX_CATEGORIES', 500),

    /*
    |--------------------------------------------------------------------------
    | Kill Switch
    |--------------------------------------------------------------------------
    |
    | The rollback ladder's first, no-deploy rung (design.md "Rollback ladder"):
    | flipping this to false must silence the coach entirely. `FinancialCoachingService::
    | speak()` (phase 4.7 — not built by this batch) MUST check this as its very
    | first line, before evaluating anything, so a disabled coach never even
    | queries a category snapshot. Not yet consumed by any code in this batch;
    | recorded here so the next batch cannot silently skip it.
    |
    */

    'enabled' => (bool) env('COACHING_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Pace Thresholds
    |--------------------------------------------------------------------------
    |
    | The tunable knobs PaceEvaluator receives through PaceThresholds
    | (design.md §5.1's decision table). PaceEvaluator itself never reaches into
    | config() (design.md D1) — the caller builds PaceThresholds from these keys.
    |
    */

    'min_day_for_projection' => (int) env('COACHING_MIN_DAY_FOR_PROJECTION', 5),

    'overrun_margin' => (float) env('COACHING_OVERRUN_MARGIN', 0.10),

    'lumpy_share' => (float) env('COACHING_LUMPY_SHARE', 0.50),

    /*
    |--------------------------------------------------------------------------
    | Max Observations Per Message
    |--------------------------------------------------------------------------
    |
    | Feeds PaceThresholds::$maxObservations, which PaceEvaluator::evaluate()
    | already truncates its output to (app/Services/Coaching/PaceEvaluator.php,
    | phase 1 — built and merged). `COACHING_MAX_OBSERVATIONS=0` is therefore a
    | second, concrete kill dial (design.md "Unprompted send volume"): once
    | phase 4.7 builds PaceThresholds from this key, setting it to 0 makes
    | evaluate() return an empty array regardless of how many categories are
    | over pace, so nothing is ever composed or sent. This is honoured by
    | already-existing code today, not merely a promised future read — see
    | tests/Feature/Coaching/CoachingConfigTest.php.
    |
    */

    'max_observations_per_message' => (int) env('COACHING_MAX_OBSERVATIONS', 3),

    /*
    |--------------------------------------------------------------------------
    | Reachable Channels
    |--------------------------------------------------------------------------
    |
    | The ordered channel preference list the coach passes to
    | ChannelIdentityResolver::preferredIdentityFor() / userIdsReachableOn()
    | (design.md D4). Telegram only — WhatsApp cannot send unprompted without a
    | Meta-approved template (ADR-0007). Admitting WhatsApp later is a config
    | change to this array, never an edit to the resolver.
    |
    */

    'channels' => ['telegram'],

];
