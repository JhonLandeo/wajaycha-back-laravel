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
    | Envelope Thresholds
    |--------------------------------------------------------------------------
    |
    | The yearly-budget half of PaceThresholds, for categories whose
    | `budget_period` is 'yearly'. An envelope is consumed, not paced, so there
    | is deliberately no projection knob here to match `overrun_margin`.
    |
    | `envelope_consumed_share` is how much of the year's budget has to be gone
    | before the coach says so; `envelope_min_months_remaining` is how much of
    | the year has to be left for saying it to be worth anything. Reporting in
    | December that 85% of a yearly budget is spent is a true statement with
    | nothing behind it.
    |
    */

    'envelope_consumed_share' => (float) env('COACHING_ENVELOPE_CONSUMED_SHARE', 0.80),

    'envelope_min_months_remaining' => (int) env('COACHING_ENVELOPE_MIN_MONTHS_REMAINING', 2),

    /*
    |--------------------------------------------------------------------------
    | Morning Budget Digest
    |--------------------------------------------------------------------------
    |
    | The standing status board (`app:send-budget-digest`), sent before the day's
    | decisions rather than after them. It is a second voice, not a second copy
    | of the coach: it repeats the same facts every morning on purpose, which is
    | exactly what `SpokenObservationLedger` forbids the coach from doing.
    |
    | `digest_enabled` turns off the morning message alone. `coaching.enabled`
    | still silences it too — a subsystem kill switch that leaves one channel
    | talking is not a kill switch.
    |
    | `digest_max_categories` is the digest's own ceiling instead of
    | `max_observations_per_message`. The coach caps at 3 because each of its
    | lines carries a cause and gets long; a digest line is one short row, and a
    | status board silently truncated to 3 would read as complete when it is not.
    |
    | `digest_hour` is read by the scheduler in `routes/console.php`. Changing it
    | is a config edit and a redeploy of the schedule, never a migration.
    |
    | The default is FALSE, unlike every other switch here. This is the only
    | feature in the subsystem that starts talking to people on its own the
    | morning after it ships, without anyone having asked for it that day. A
    | default of true would mean the deploy itself is the decision to send it,
    | and that decision belongs to the owner, on a date they picked. Ship dark,
    | then set `COACHING_DIGEST_ENABLED=true` per environment.
    |
    */

    'digest_enabled' => (bool) env('COACHING_DIGEST_ENABLED', false),

    'digest_max_categories' => (int) env('COACHING_DIGEST_MAX_CATEGORIES', 10),

    'digest_hour' => (string) env('COACHING_DIGEST_HOUR', '08:00'),

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
    | Month-Over-Month Comparison
    |--------------------------------------------------------------------------
    |
    | The knobs `MonthOverMonthComparator` receives, for the menu button that
    | answers "¿qué cambió desde el mes pasado?". Like every other decider in the
    | subsystem it never reads config() itself — the service passes these in.
    |
    | `shift_min_amount` is the floor in soles below which a category's movement
    | is not reported. Every category drifts a little every month; without a floor
    | the answer is a wall of two-sol movements with the one real finding buried
    | in it. It is an absolute amount and not a percentage on purpose: S/ 2
    | becoming S/ 20 is "+900%", reads like an emergency, and is eighteen soles.
    |
    | `shift_max_categories` caps how many survive the ranking. Smaller than
    | `digest_max_categories` because these lines carry two figures and a
    | direction, not one short row, and because this answer is a Pareto reading —
    | the point is the handful that explain most of the change.
    |
    */

    'shift_min_amount' => (float) env('COACHING_SHIFT_MIN_AMOUNT', 20.0),

    'shift_max_categories' => (int) env('COACHING_SHIFT_MAX_CATEGORIES', 5),

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
