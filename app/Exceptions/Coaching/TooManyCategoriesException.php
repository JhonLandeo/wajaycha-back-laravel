<?php

declare(strict_types=1);

namespace App\Exceptions\Coaching;

use RuntimeException;

/**
 * Thrown when `get_monthly_category_budget_report`'s own `total_records` exceeds
 * `config('coaching.max_categories')`.
 *
 * Design.md D2 (item 1): coaching a truncated category list would produce
 * confidently wrong silence — some category past its budget would simply never be
 * evaluated because it fell off the page. Failing loudly is the deliberate
 * alternative to that silent truncation.
 */
final class TooManyCategoriesException extends RuntimeException
{
    public static function forUser(int $userId, int $totalRecords, int $maxCategories): self
    {
        return new self(sprintf(
            'User %d has %d categories, exceeding the coaching ceiling of %d. Refusing to coach a truncated category list.',
            $userId,
            $totalRecords,
            $maxCategories,
        ));
    }
}
