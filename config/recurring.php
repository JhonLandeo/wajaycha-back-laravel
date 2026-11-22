<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Detection Window
    |--------------------------------------------------------------------------
    |
    | How many months of movements the reading looks at, counted back from today.
    |
    | Two years and not one, because an annual subscription only shows up twice
    | in this window — the charge from a year ago and the one that just arrived.
    | With twelve months there would be one occurrence and nothing to compare it
    | against, so every yearly plan would be invisible by construction.
    |
    | Longer is not better: a subscription cancelled eighteen months ago would
    | still be named, and the page would describe a bill the user no longer pays.
    |
    */

    'window_months' => (int) env('RECURRING_WINDOW_MONTHS', 24),

    /*
    |--------------------------------------------------------------------------
    | Amount Stability
    |--------------------------------------------------------------------------
    |
    | Coefficient of variation of the charge amounts at or below which the bill
    | counts as steady. A ratio rather than a number of soles: a S/ 2,000 rent and
    | a S/ 15 streaming plan should be judged by how much each one moves against
    | its own size, not against each other.
    |
    | 0.20 tolerates a price change, a tax adjustment and a small exchange-rate
    | movement. It is deliberately below what a weekly grocery run looks like —
    | that habit varies far more and is not a subscription.
    |
    */

    'variation_threshold' => (float) env('RECURRING_VARIATION_THRESHOLD', 0.20),

    /*
    |--------------------------------------------------------------------------
    | Interval Regularity
    |--------------------------------------------------------------------------
    |
    | A steady amount is not enough on its own: two identical purchases many
    | months apart are not a plan. `regular_share` is the share of gaps that must
    | sit near the median gap before the spacing counts as a rhythm, so one
    | skipped month is tolerated in a long series and not in a short one.
    |
    | `interval_tolerance_share` is how far a single gap may drift from the
    | nominal cadence, as a share of it, capped at 30 days. A monthly charge may
    | land a few days early or late without stopping being monthly.
    |
    */

    'regular_share' => (float) env('RECURRING_REGULAR_SHARE', 0.75),

    'interval_tolerance_share' => (float) env('RECURRING_INTERVAL_TOLERANCE_SHARE', 0.20),

];
