<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a category's spending is something the user decides each month, or
 * something that simply arrives.
 *
 * **Not a stored column, and there is none.** Nothing in the schema says "fixed":
 * `is_frequent` and `get_transactions_by_detail`'s `HAVING COUNT(t.id) > 1`
 * answer how OFTEN a merchant appears, which is a different question — groceries
 * are frequent and discretionary at once. `budget_period` distinguishes a rhythm
 * from an envelope, not a commitment from a choice. So this is derived, from the
 * one property the two actually differ in: rent is the same number every month
 * and delivery is not.
 *
 * Three cases and not a boolean. The third is the honest one: a category that has
 * not existed long enough to have a shape cannot be filed under either, and
 * guessing would put a two-month-old rent in the same list as impulse spending.
 * Silence about it is worse still — a split that quietly omits part of the month
 * reads as covering all of it.
 */
enum SpendingRhythm: string
{
    /** Arrives at roughly the same amount every month. */
    case FIXED = 'fixed';

    /** Swings month to month, which is what deciding looks like in the figures. */
    case DISCRETIONARY = 'discretionary';

    /** Too little history to have a shape yet. */
    case TOO_NEW = 'too_new';
}
